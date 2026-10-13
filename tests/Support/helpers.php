<?php

declare(strict_types=1);

use CovProto\InstrumentingVisitor;
use CovProto\Probe;
use CovProto\SourceEditor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

final class TemporaryDirectory
{
    /** @var list<string> */
    public static array $created = [];

    public static function create(): string
    {
        $directory = sys_get_temp_dir() . '/covproto-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        self::$created[] = $directory;

        return (string) realpath($directory);
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}

/**
 * Runs the visitor over a piece of code and applies its edits.
 *
 * @return array{0: string, 1: array<int, Probe>}
 */
function instrumentCode(string $code, bool $strict = false, bool $fast = false, string $path = '/project/src/File.php'): array
{
    $parser = (new ParserFactory)->createForHostVersion();
    $ast    = (array) $parser->parse($code);

    $visitor = new InstrumentingVisitor($parser->getTokens(), 'f', $path, $strict, $fast);
    $visitor->instrumentTopLevel($ast);
    (new NodeTraverser($visitor))->traverse($ast);

    return [SourceEditor::apply($code, $visitor->edits()), $visitor->probes()];
}

/**
 * Expands probe placeholders of an expected instrumented source:
 * {pN} statement probe, {oN} opening of a wrapped expression, {c} its closing,
 * {lN} statement probe through the local reference, {LN} wrap opening through it,
 * {ref} the local reference binding.
 */
function expected(string $template): string
{
    return (string) preg_replace_callback(
        '/\{(p|o|l|L)(\d+)\}|\{c\}|\{ref\}/',
        static function (array $match): string {
            return match ($match[0]) {
                '{c}'   => ') : null)',
                '{ref}' => '$__cov = &\CovProto\Hits::$current[\'f\'];',
                default => match ($match[1]) {
                    'p'     => '\CovProto\Hits::$current[\'f\'][' . $match[2] . ']=1;',
                    'o'     => '((\CovProto\Hits::$current[\'f\'][' . $match[2] . ']=1) ? (',
                    'l'     => '$__cov[' . $match[2] . ']=1;',
                    default => '(($__cov[' . $match[2] . ']=1) ? (',
                },
            };
        },
        $template,
    );
}
