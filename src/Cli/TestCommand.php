<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;
use RuntimeException;
use Sonda\{Filesystem, Run, Scope};
use Sonda\Git\ChangedLines;
use Sonda\Project\{Runner, SuiteConfiguration, TestConfiguration, TestSelection, ViewFolders};
use Sonda\Report\{ChangeCoverage, CoverageBuilder, SourceFiles, Uncovered};
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\{Clover, Cobertura, PHP};
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;

use function implode, str_ends_with, str_starts_with, substr;

/**
 * `sonda test`: runs the project's tests instrumented, then reports the coverage of the
 * `<source>` of its phpunit.xml. With `--changed=<ref>` or `--files=`, only those files are
 * measured and anything left uncovered fails the command: in a changed file, the changed lines
 * and the methods they touch; in a new file or one named in `--files=`, the whole file.
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
        $scope = new Scope([...$configuration->sourceDirectories, ...$viewDirectories], $configuration->excludedDirectories, [...$explicitFiles, ...array_keys($changes)]);
        $sourceFiles = $this->sourceFiles($scope, $viewDirectories);

        if ($scope->narrowsToFiles() && $sourceFiles === []) {
            fwrite($output, "Nenhum arquivo alterado dentro de <source>: nada a medir.\n");

            return 0;
        }

        $workDirectory = $this->temporaryRoot . '/' . substr(sha1($projectRoot), 0, 12);
        Filesystem::privateDirectory($this->temporaryRoot);
        Filesystem::privateDirectory($workDirectory);
        // php-code-coverage keeps real paths: a symlinked temporary directory would lose the views' data.
        $workDirectory = (string) realpath($workDirectory);
        $runsDirectory = $workDirectory . '/runs-' . bin2hex(random_bytes(4));
        [$runnerArguments, $generatedConfiguration] = $this->runnerArguments($options, $configuration, $projectRoot);

        try {
            $status = $this->processes->run(
                [...Runner::command($projectRoot, $options->parallel), '--no-coverage', '--bootstrap=' . $this->sondaRoot . '/bootstrap.php', ...$runnerArguments],
                $this->environment($options, $configuration, $projectRoot, $sourceFiles, $viewDirectories, $workDirectory . '/cache', $runsDirectory),
                $projectRoot,
            );
        } finally {
            if ($generatedConfiguration !== null) {
                unlink($generatedConfiguration);
            }
        }

        try {
            $coverage = CoverageBuilder::build(Run::loadDirectory($runsDirectory), $sourceFiles);
        } catch (RuntimeException $runtimeException) {
            fwrite($output, "\nSem dados de cobertura: {$runtimeException->getMessage()}\n");

            return $status === 0 ? 1 : $status;
        } finally {
            Filesystem::removeDirectory($runsDirectory);
        }

        fwrite($output, "\n" . $this->summary($coverage) . "\n");
        $this->writeReports($options, $coverage, $projectRoot, $output);

        if (! $scope->narrowsToFiles()) {
            return $status;
        }

        $change = Uncovered::ofChanges($coverage, $changes);

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
     * @param list<string> $sourceFiles
     * @param list<string> $viewDirectories
     *
     * @return array<string, string>
     */
    private function environment(TestOptions $options, TestConfiguration $configuration, string $projectRoot, array $sourceFiles, array $viewDirectories, string $cacheDirectory, string $runsDirectory): array
    {
        $autoload = $projectRoot . '/vendor/autoload.php';
        $chained = $configuration->bootstrap !== null && realpath($configuration->bootstrap) !== realpath($autoload) ? $configuration->bootstrap : '';

        $environment = [];
        $environment['SONDA_AUTOLOAD'] = $autoload;
        $environment['SONDA_TARGET'] = implode(PATH_SEPARATOR, [...$configuration->sourceDirectories, ...$viewDirectories]);
        $environment['SONDA_VIEWS'] = implode(PATH_SEPARATOR, $viewDirectories);
        $environment['SONDA_EXCLUDE'] = implode(PATH_SEPARATOR, $configuration->excludedDirectories);
        $environment['SONDA_ONLY'] = $options->changed !== null || $options->files !== [] ? implode(PATH_SEPARATOR, $sourceFiles) : '';
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
     * The arguments for the runner. ParaTest takes a single test path on its command line: with
     * several, in parallel, they go into a configuration generated beside the project's one.
     *
     * @return array{0: list<string>, 1: string|null} the arguments and the generated configuration, to remove after the run
     */
    private function runnerArguments(TestOptions $options, TestConfiguration $configuration, string $projectRoot): array
    {
        $selection = TestSelection::of($options->runnerArguments);

        if (! $options->parallel || count($selection->paths) <= 1) {
            return [$options->runnerArguments, null];
        }

        $generated = SuiteConfiguration::write($configuration->path, $selection->paths, $projectRoot);

        return [[...$selection->options, '--configuration=' . $generated], $generated];
    }

    private function summary(CodeCoverage $coverage): string
    {
        $report = $coverage->getReport();

        return sprintf(
            'Cobertura: linhas %d/%d (%s), branches %d/%d (%s)',
            $report->numberOfExecutedLines(),
            $report->numberOfExecutableLines(),
            $this->percentage($report->numberOfExecutedLines(), $report->numberOfExecutableLines()),
            $report->numberOfExecutedBranches(),
            $report->numberOfExecutableBranches(),
            $this->percentage($report->numberOfExecutedBranches(), $report->numberOfExecutableBranches()),
        );
    }

    private function changeSummary(ChangeCoverage $change): string
    {
        return sprintf(
            'Do que mudou: linhas %d/%d (%s), branches %d/%d (%s)',
            $change->executedLines,
            $change->executableLines,
            $this->percentage($change->executedLines, $change->executableLines),
            $change->executedBranches,
            $change->executableBranches,
            $this->percentage($change->executedBranches, $change->executableBranches),
        );
    }

    private function percentage(int $covered, int $total): string
    {
        if ($total === 0) {
            return '100,00%';
        }

        return number_format($covered * 100 / $total, 2, ',', '.') . '%';
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
