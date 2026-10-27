<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;
use RuntimeException;
use Sonda\{ComposerFiles, Filesystem, Run, Scope};
use Sonda\Git\{ChangedLines, ChangedPaths, Git};
use Sonda\Project\{AffectedTests, DirectRequires, Runner, SuiteConfiguration, TestConfiguration, TestMap, TestSelection, ViewFolders};
use Sonda\Report\{ChangeCoverage, CodeQuality, CoverageBuilder, SourceFiles, TextSummary, Uncovered};
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\{Clover, Cobertura, PHP};
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;

use function dirname, implode, str_ends_with, str_starts_with, substr, trim;

/**
 * `sonda test`: runs the project's tests instrumented, then reports the coverage of the
 * `<source>` of its phpunit.xml. With `--changed=<ref>` or `--files=`, only those files are
 * measured and anything left uncovered fails the command: in a changed file, the changed lines
 * and the methods they touch; in a new file or one named in `--files=`, the whole file.
 *
 * @phpstan-import-type Gaps from Uncovered
 */
final readonly class TestCommand
{
    public function __construct(
        private ProcessRunner $processes,
        private string $sondaRoot,
        private string $temporaryRoot,
    ) {
    }

    /**
     * @param resource $output
     *
     * @return int the exit code
     */
    public function run(TestOptions $options, string $projectRoot, $output): int
    {
        $configuration = TestConfiguration::load(
            $options->configuration !== null ? $this->absolute($options->configuration, $projectRoot) : TestConfiguration::find($projectRoot),
        );
        $changes = $options->changed !== null ? ChangedLines::since($projectRoot, $options->changed) : [];
        $explicitFiles = $this->explicitFiles($options, $projectRoot);

        // A file named explicitly is checked whole, even when it also changed.
        foreach ($explicitFiles as $file) {
            unset($changes[$file]);
        }

        $viewDirectories = $this->viewDirectories($options, $projectRoot);
        $sourceDirectories = [...$configuration->sourceDirectories, ...$viewDirectories];
        $narrowed = new Scope($sourceDirectories, $configuration->excludedDirectories, [...$explicitFiles, ...array_keys($changes)]);
        // The files the verdict checks: those of --changed and --files, or none to check (null) without them.
        $checkedFiles = $narrowed->narrowsToFiles() ? $this->sourceFiles($narrowed, $viewDirectories) : null;
        $hasFloor = $options->minLines !== null || $options->minBranches !== null;

        if ($checkedFiles === []) {
            if (! $hasFloor) {
                fwrite($output, "Nenhum arquivo alterado dentro de <source>: nada a medir.\n");
                // The reports asked for still exist, empty, for whatever reads them after the job.
                $this->writeReports($options, CoverageBuilder::empty(), $projectRoot, $output);
                $this->writeCodeQuality($options, [], $projectRoot, $output);

                return 0;
            }

            fwrite($output, "Nenhum arquivo alterado dentro de <source>: com piso de cobertura, o projeto inteiro é medido.\n");
            $checkedFiles = null;
        }

        $workDirectory = $this->temporaryRoot . '/' . substr(sha1($projectRoot), 0, 12);
        Filesystem::privateDirectory($this->temporaryRoot);
        Filesystem::privateDirectory($workDirectory);
        // php-code-coverage keeps real paths: a symlinked temporary directory would lose the views' data.
        $workDirectory = (string) realpath($workDirectory);
        $runsDirectory = $workDirectory . '/runs-' . bin2hex(random_bytes(4));
        $mapPath = $options->map !== null ? $this->reportPath($options->map, $projectRoot) : $workDirectory . '/tests.map';
        $wholeProject = new Scope($sourceDirectories, $configuration->excludedDirectories);
        // Null when --affected runs the whole suite: then the whole project is measured, for the map to learn all of it.
        $affectedTests = $options->affected ? $this->affectedTests($options, $configuration, $projectRoot, $explicitFiles, $this->sourceFiles($wholeProject, $viewDirectories), $hasFloor, $mapPath, $output) : [];

        // A floor is a promise about the whole project: with one, the whole project is measured, and
        // the verdict of --changed and --files still looks only at their files.
        $scope = $hasFloor || $affectedTests === null ? $wholeProject : $narrowed;
        $sourceFiles = $this->sourceFiles($scope, $viewDirectories);
        $this->refuseDoubleDeclarations($sourceFiles, $scope, $projectRoot);
        [$runnerArguments, $generatedConfiguration] = $this->runnerArguments($options, $configuration, $projectRoot, $affectedTests ?? []);

        try {
            $status = $this->processes->run(
                [...Runner::command($projectRoot, $options->parallel), '--no-coverage', '--bootstrap=' . $this->sondaRoot . '/bootstrap.php', ...$runnerArguments],
                $this->environment($configuration, $projectRoot, $scope->narrowsToFiles() ? $sourceFiles : [], $viewDirectories, $workDirectory . '/cache', $runsDirectory, $options),
                $projectRoot,
            );
        } finally {
            if ($generatedConfiguration !== null) {
                unlink($generatedConfiguration);
            }
        }

        try {
            $run = Run::loadDirectory($runsDirectory);
            $coverage = CoverageBuilder::build($run, $sourceFiles, $options->coveredBy);
            TestMap::load($mapPath, $projectRoot)->updatedWith($run, $sourceFiles)->save($mapPath, $projectRoot);
        } catch (RuntimeException $runtimeException) {
            fwrite($output, "\nSem dados de cobertura: {$runtimeException->getMessage()}\n");

            return $status === 0 ? 1 : $status;
        } finally {
            Filesystem::removeDirectory($runsDirectory);
        }

        fwrite($output, "\n" . $this->summary($coverage) . "\n");

        foreach ($run->problems as $problem) {
            fwrite($output, "Aviso: o Sonda não leu o arquivo ou o tamanho de algum teste; a cobertura não muda, mas o --affected e o --covered-by podem sair incompletos: {$problem}\n");
        }

        if ($options->text) {
            fwrite($output, TextSummary::of($coverage, $projectRoot) . "\n");
        }

        $belowMinimum = $this->belowMinimum($options, $coverage, $output);
        $status = $status === 0 && $belowMinimum ? 1 : $status;
        $this->writeReports($options, $coverage, $projectRoot, $output);

        if ($checkedFiles === null) {
            // The gaps of the whole project only when the whole project is what was asked about: with
            // --changed or --files and nothing of them in scope, the floor measured it all, but nothing
            // changed for the merge request to point at.
            $asksAboutTheWholeProject = $options->changed === null && $options->files === [];
            $this->writeCodeQuality($options, $options->codeQuality !== null && $asksAboutTheWholeProject ? Uncovered::of($coverage) : [], $projectRoot, $output);

            return $status;
        }

        $change = Uncovered::ofChanges($coverage, $changes, $checkedFiles);
        $this->writeCodeQuality($options, $change->uncovered, $projectRoot, $output);

        if ($options->changed !== null) {
            fwrite($output, $this->changeSummary($change) . "\n");
        }

        if ($change->uncovered === []) {
            fwrite($output, ($options->changed !== null ? 'O que mudou está 100% coberto.' : 'Os arquivos alterados estão 100% cobertos.') . "\n");

            return $status;
        }

        fwrite($output, "Sem cobertura:\n" . Uncovered::describe($change->uncovered, $projectRoot) . "\n");

        return $status === 0 ? 1 : $status;
    }

    /**
     * The files of `--files=`, as the real paths the report uses when they exist.
     *
     * @return list<string>
     */
    private function explicitFiles(TestOptions $options, string $projectRoot): array
    {
        $files = [];

        foreach ($options->files as $file) {
            $absolute = $this->absolute($file, $projectRoot);
            $files[] = realpath($absolute) ?: $absolute;
        }

        return $files;
    }

    /**
     * The files of the scope: the PHP files of the source and the Blade views of the view directories.
     *
     * @param list<string> $viewDirectories
     *
     * @return list<string>
     */
    private function sourceFiles(Scope $scope, array $viewDirectories): array
    {
        $files = [];

        foreach (SourceFiles::findAll($scope->targetDirectories(), $scope->excludedDirectories()) as $file) {
            if ($scope->includes($file) && (str_ends_with($file, '.blade.php') || ! $this->isUnder($file, $viewDirectories))) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The Blade views: `resources/views` of a Laravel project and of the packages and modules kept
     * in it, plus the directories of `--views`; `--no-blade` leaves them all out.
     *
     * @return list<string>
     */
    private function viewDirectories(TestOptions $options, string $projectRoot): array
    {
        if (! $options->blade) {
            return [];
        }

        $directories = [];
        $found = is_file($projectRoot . '/artisan') ? ViewFolders::in($projectRoot) : [];

        foreach ([...$found, ...$options->views] as $directory) {
            $realDirectory = realpath(str_starts_with($directory, '/') ? $directory : $projectRoot . '/' . $directory);

            if ($realDirectory === false) {
                throw new InvalidArgumentException("A pasta de views {$directory} não existe.");
            }

            $directories[$realDirectory] = $realDirectory;
        }

        return array_values($directories);
    }

    /** @param list<string> $directories */
    private function isUnder(string $file, array $directories): bool
    {
        return array_any($directories, static fn (string $directory): bool => str_starts_with($file, $directory . '/'));
    }

    /**
     * @param list<string> $onlyFiles       the files to instrument, or all of the source when empty
     * @param list<string> $viewDirectories
     *
     * @return array<string, string>
     */
    private function environment(TestConfiguration $configuration, string $projectRoot, array $onlyFiles, array $viewDirectories, string $cacheDirectory, string $runsDirectory, TestOptions $options): array
    {
        $autoload = $projectRoot . '/vendor/autoload.php';
        $chained = $configuration->bootstrap !== null && realpath($configuration->bootstrap) !== realpath($autoload) ? $configuration->bootstrap : '';

        $environment = [];
        $environment['SONDA_AUTOLOAD'] = $autoload;
        $environment['SONDA_TARGET'] = implode(PATH_SEPARATOR, [...$configuration->sourceDirectories, ...$viewDirectories]);
        $environment['SONDA_VIEWS'] = implode(PATH_SEPARATOR, $viewDirectories);
        $environment['SONDA_EXCLUDE'] = implode(PATH_SEPARATOR, $configuration->excludedDirectories);
        $environment['SONDA_ONLY'] = implode(PATH_SEPARATOR, $onlyFiles);
        $environment['SONDA_CACHE'] = $cacheDirectory;
        $environment['SONDA_OUT'] = $runsDirectory;
        $environment['SONDA_STRICT'] = $options->strict ? '1' : '0';
        $environment['SONDA_FAST'] = $options->fast ? '1' : '0';
        $environment['SONDA_CHAIN'] = $chained;

        return $environment;
    }

    /** @param resource $output */
    private function writeReports(TestOptions $options, CodeCoverage $coverage, string $projectRoot, $output): void
    {
        if ($options->html !== null) {
            $directory = $this->absolute($options->html, $projectRoot);
            new HtmlReport()->process($coverage, $directory);
            fwrite($output, "Relatório HTML em {$directory}\n");
        }

        if ($options->clover !== null) {
            $file = $this->reportPath($options->clover, $projectRoot);
            new Clover()->process($coverage, $file);
            fwrite($output, "Relatório Clover em {$file}\n");
        }

        if ($options->cov !== null) {
            $file = $this->reportPath($options->cov, $projectRoot);
            new PHP()->process($coverage, $file);
            fwrite($output, "Relatório .cov em {$file}\n");
        }

        if ($options->cobertura !== null) {
            $file = $this->reportPath($options->cobertura, $projectRoot);
            new Cobertura()->process($coverage, $file);
            fwrite($output, "Relatório Cobertura em {$file}\n");
        }
    }

    /**
     * The gaps as a GitLab Code Quality report, with paths from the root of the repository, which
     * is where GitLab reads them from: a project in a subdirectory of it is not.
     *
     * @param array<string, Gaps> $uncovered
     * @param resource            $output
     */
    private function writeCodeQuality(TestOptions $options, array $uncovered, string $projectRoot, $output): void
    {
        if ($options->codeQuality === null) {
            return;
        }

        try {
            $root = trim(Git::run($projectRoot, ['rev-parse', '--show-toplevel']));
        } catch (RuntimeException) {
            $root = $projectRoot;
        }

        $file = $this->reportPath($options->codeQuality, $projectRoot);
        Filesystem::ensureDirectory(dirname($file));
        file_put_contents($file, CodeQuality::of($uncovered, $root));
        fwrite($output, "Relatório Code Quality em {$file}\n");
    }

    /**
     * A file of the scope that Composer loads from its `files` and that another file of the scope
     * also requires by its path would be declared twice under the Sonda, from the instrumented copy
     * and from its own path: that stops the run before anything runs.
     *
     * @param list<string> $sourceFiles
     */
    private function refuseDoubleDeclarations(array $sourceFiles, Scope $scope, string $projectRoot): void
    {
        $listed = array_flip(ComposerFiles::listed($projectRoot . '/vendor/autoload.php'));

        foreach (DirectRequires::in($sourceFiles, $scope) as $require) {
            $where = TextSummary::relative($require->file, $projectRoot) . ':' . $require->line;
            $target = TextSummary::relative($require->target, $projectRoot);

            // A file required by its path is measured too; one Composer also loads from its `files`
            // comes from the instrumented copy there and from its own path here: declared twice.
            if (isset($listed[$require->target])) {
                throw new RuntimeException("{$target} está na chave files do Composer e ainda é carregado por require direto em {$where}: sob o Sonda seria declarado duas vezes. Tire o require.");
            }
        }
    }

    /**
     * The arguments for the runner. ParaTest takes a single test path on its command line: with
     * several, in parallel, they go into a configuration generated beside the project's one.
     *
     * @param list<string> $affectedTests the test files `--affected` chose, after the runner's own arguments
     *
     * @return array{0: list<string>, 1: string|null} the arguments and the generated configuration, to remove after the run
     */
    private function runnerArguments(TestOptions $options, TestConfiguration $configuration, string $projectRoot, array $affectedTests): array
    {
        $arguments = [...$options->runnerArguments, ...$affectedTests];
        $selection = TestSelection::of($arguments);

        if (! $options->parallel || count($selection->paths) <= 1) {
            return [$arguments, null];
        }

        $generated = SuiteConfiguration::write($configuration->path, $selection->paths, $projectRoot);

        return [[...$selection->options, '--configuration=' . $generated], $generated];
    }

    /**
     * The test files `--affected` runs, or null when the map sends the whole suite. With a coverage
     * floor, or test paths given to the runner, `--affected` stands aside (none).
     *
     * @param list<string> $explicitFiles
     * @param list<string> $allSources    every source file of the project
     * @param resource     $output
     *
     * @return list<string>|null
     */
    private function affectedTests(TestOptions $options, TestConfiguration $configuration, string $projectRoot, array $explicitFiles, array $allSources, bool $hasFloor, string $mapPath, $output): ?array
    {
        if ($hasFloor) {
            fwrite($output, "--affected ignorado: com piso de cobertura, a suíte inteira roda.\n");

            return [];
        }

        if (TestSelection::of($options->runnerArguments)->paths !== []) {
            fwrite($output, "--affected ignorado: os testes a rodar já foram dados.\n");

            return [];
        }

        $changed = $options->changed !== null ? ChangedPaths::since($projectRoot, $options->changed) : $explicitFiles;
        // The map itself, kept in the project for CI to cache, is no change of the project.
        $map = realpath($mapPath) ?: $mapPath;
        $changed = array_values(array_filter($changed, static fn (string $path): bool => $path !== $map));
        $testMap = TestMap::load($mapPath, $projectRoot);
        $affected = AffectedTests::of($changed, $testMap, $allSources, $configuration->testPaths);

        if ($affected->tests === null) {
            fwrite($output, "Rodando a suíte inteira e medindo o projeto todo: {$affected->reason}.\n");

            return null;
        }

        // Up to ten test files are named; more only counted, against the test files the map knows.
        $which = count($affected->tests) <= 10
            ? implode(', ', array_map(static fn (string $test): string => TextSummary::relative($test, $projectRoot), $affected->tests))
            : count($affected->tests) . ' de ' . count($testMap->sources) . ' arquivos de teste';
        fwrite($output, "Rodando só os testes afetados: {$which}\n");

        return $affected->tests;
    }

    private function summary(CodeCoverage $coverage): string
    {
        $report = $coverage->getReport();

        return sprintf(
            'Cobertura: linhas %d/%d (%s), branches %d/%d (%s)',
            $report->numberOfExecutedLines(),
            $report->numberOfExecutableLines(),
            TextSummary::percentage($report->numberOfExecutedLines(), $report->numberOfExecutableLines()),
            $report->numberOfExecutedBranches(),
            $report->numberOfExecutableBranches(),
            TextSummary::percentage($report->numberOfExecutedBranches(), $report->numberOfExecutableBranches()),
        );
    }

    /**
     * Whether the whole report falls below the floors of --min-lines and --min-branches, saying so.
     *
     * @param resource $output
     */
    private function belowMinimum(TestOptions $options, CodeCoverage $coverage, $output): bool
    {
        $report = $coverage->getReport();
        $measures = [];
        $measures['linhas'] = [$options->minLines, $report->numberOfExecutedLines(), $report->numberOfExecutableLines()];
        $measures['branches'] = [$options->minBranches, $report->numberOfExecutedBranches(), $report->numberOfExecutableBranches()];
        $below = [];

        foreach ($measures as $name => [$minimum, $covered, $total]) {
            if ($minimum !== null && TextSummary::ratio($covered, $total) < $minimum) {
                $below[] = $name . ' ' . TextSummary::percentage($covered, $total) . ' < ' . TextSummary::format($minimum);
            }
        }

        if ($below === []) {
            return false;
        }

        fwrite($output, 'Cobertura abaixo do mínimo: ' . implode(', ', $below) . "\n");

        return true;
    }

    private function changeSummary(ChangeCoverage $change): string
    {
        return sprintf(
            'Do que mudou: linhas %d/%d (%s), branches %d/%d (%s)',
            $change->executedLines,
            $change->executableLines,
            TextSummary::percentage($change->executedLines, $change->executableLines),
            $change->executedBranches,
            $change->executableBranches,
            TextSummary::percentage($change->executedBranches, $change->executableBranches),
        );
    }

    private function absolute(string $path, string $root): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * @param non-empty-string $path
     *
     * @return non-empty-string
     */
    private function reportPath(string $path, string $root): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }
}
