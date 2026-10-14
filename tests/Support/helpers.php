<?php

declare(strict_types=1);

use Sonda\{Filesystem, Hits, InstrumentingVisitor, Probe, SourceEditor};
use PhpParser\{NodeTraverser, ParserFactory};

final class TemporaryDirectory
{
    /** @var list<string> */
    public static array $created = [];

    public static function create(): string
    {
        $directory = sys_get_temp_dir() . '/sonda-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        self::$created[] = $directory;

        return (string) realpath($directory);
    }

    public static function remove(string $directory): void
    {
        Filesystem::removeDirectory($directory);
    }
}

/**
 * Runs the visitor over a piece of code and applies its edits.
 *
 * @return array{0: string, 1: array<int, Probe>}
 */
function instrumentCode(string $code, bool $strict = false, bool $fast = false, string $path = '/project/src/File.php'): array
{
    $parser = new ParserFactory()->createForHostVersion();
    $ast = (array) $parser->parse($code);

    $visitor = new InstrumentingVisitor($parser->getTokens(), 'f', $path, $strict, $fast);
    $visitor->instrumentTopLevel($ast);
    new NodeTraverser($visitor)->traverse($ast);

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
        static fn (array $match): string => match ($match[0]) {
            '{c}' => ') : null)',
            '{ref}' => '$__sonda = &\\' . Hits::class . '::$current[\'f\'];',
            default => match ($match[1]) {
                'p' => '\\' . Hits::class . '::$current[\'f\'][' . $match[2] . ']=1;',
                'o' => '((\\' . Hits::class . '::$current[\'f\'][' . $match[2] . ']=1) ? (',
                'l' => '$__sonda[' . $match[2] . ']=1;',
                default => '(($__sonda[' . $match[2] . ']=1) ? (',
            },
        },
        $template,
    );
}
