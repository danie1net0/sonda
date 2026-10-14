<?php

declare(strict_types=1);

namespace Sonda\Cli;

use RuntimeException;
use Sonda\{Filesystem, Run, Scope};
use Sonda\Git\ChangedFiles;
use Sonda\Project\{Runner, TestConfiguration};
use Sonda\Report\{CoverageBuilder, SourceFiles, Uncovered};
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\{Clover, PHP};
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;

use function implode, str_starts_with, substr;

/**
 * `sonda test`: runs the project's tests instrumented, then reports the coverage of the
 * `<source>` of its phpunit.xml. With `--changed=<ref>` or `--files=`, only those files are
 * measured and anything left uncovered fails the command.
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
            $options->configuration !== null ? self::absolute($options->configuration, $projectRoot) : TestConfiguration::find($projectRoot),
        );
        $onlyFiles = $this->onlyFiles($options, $projectRoot);
        $scope = new Scope($configuration->sourceDirectories, $configuration->excludedDirectories, $onlyFiles);
        $sourceFiles = $this->sourceFiles($scope);

        if ($scope->narrowsToFiles() && $sourceFiles === []) {
            fwrite($output, "Nenhum arquivo alterado dentro de <source>: nada a medir.\n");

            return 0;
        }

        $workDirectory = $this->temporaryRoot . '/' . substr(sha1($projectRoot), 0, 12);
        $runsDirectory = $workDirectory . '/runs-' . bin2hex(random_bytes(4));
        Filesystem::privateDirectory($this->temporaryRoot);
        Filesystem::privateDirectory($workDirectory);
        $status = $this->processes->run(
            [...Runner::command($projectRoot, $options->parallel), '--no-coverage', '--bootstrap=' . $this->sondaRoot . '/bootstrap.php', ...$options->runnerArguments],
            $this->environment($options, $configuration, $projectRoot, $sourceFiles, $workDirectory . '/cache', $runsDirectory),
            $projectRoot,
        );

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

        $uncovered = Uncovered::of($coverage);

        if ($uncovered === []) {
            fwrite($output, "Os arquivos alterados estão 100% cobertos.\n");

            return $status;
        }

        fwrite($output, "Sem cobertura:\n" . Uncovered::describe($uncovered, $projectRoot) . "\n");

        return $status === 0 ? 1 : $status;
    }

    /** @return list<string> */
    private function onlyFiles(TestOptions $options, string $projectRoot): array
    {
        $files = array_map(static fn (string $file): string => self::absolute($file, $projectRoot), $options->files);

        if ($options->changed !== null) {
            return [...$files, ...ChangedFiles::since($projectRoot, $options->changed)];
        }

        return $files;
    }

    /** @return list<string> */
    private function sourceFiles(Scope $scope): array
    {
        $files = [];

        foreach (SourceFiles::findAll($scope->targetDirectories(), $scope->excludedDirectories()) as $file) {
            if ($scope->includes($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $sourceFiles
     *
     * @return array<string, string>
     */
    private function environment(TestOptions $options, TestConfiguration $configuration, string $projectRoot, array $sourceFiles, string $cacheDirectory, string $runsDirectory): array
    {
        $autoload = $projectRoot . '/vendor/autoload.php';
        $chained = $configuration->bootstrap !== null && realpath($configuration->bootstrap) !== realpath($autoload) ? $configuration->bootstrap : '';

        $environment = [];
        $environment['SONDA_AUTOLOAD'] = $autoload;
        $environment['SONDA_TARGET'] = implode(PATH_SEPARATOR, $configuration->sourceDirectories);
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
            $directory = self::absolute($options->html, $projectRoot);
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

    private function percentage(int $covered, int $total): string
    {
        if ($total === 0) {
            return '100,00%';
        }

        return number_format($covered * 100 / $total, 2, ',', '.') . '%';
    }

    private static function absolute(string $path, string $root): string
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
