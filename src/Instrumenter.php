<?php

declare(strict_types=1);

namespace Sonda;

use PhpParser\{NodeTraverser, Parser, ParserFactory};
use RuntimeException;

/**
 * Source-level instrumenter: inserts probe text at byte offsets of the original source,
 * so line numbers never move.
 */
final readonly class Instrumenter
{
    private Parser $parser;

    /**
     * @param bool $strict every `if` needs both outcomes tested: an `else` is synthesized when missing
     * @param bool $fast   bind a local reference once per call, so each probe is a single assignment
     */
    public function __construct(
        private bool $strict = false,
        private bool $fast = false,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForHostVersion();
    }

    public function instrument(string $path, string $fileId): InstrumentationResult
    {
        return $this->instrumentCode(Filesystem::read($path), $path, $fileId);
    }

    /** @param string $path what `__FILE__` and `__DIR__` in the code stand for */
    /** @param string $mainScope the function name of the file-level code (see InstrumentingVisitor) */
    public function instrumentCode(string $code, string $path, string $fileId, string $mainScope = '{main}'): InstrumentationResult
    {
        $ast = $this->parser->parse($code);

        if ($ast === null) {
            throw new RuntimeException("O arquivo {$path} não pôde ser analisado.");
        }

        $visitor = new InstrumentingVisitor($this->parser->getTokens(), $fileId, $path, $this->strict, $this->fast, $mainScope);
        $visitor->instrumentTopLevel($ast);
        new NodeTraverser($visitor)->traverse($ast);

        return new InstrumentationResult(SourceEditor::apply($code, $visitor->edits()), $visitor->probes());
    }

    /** Changes whenever the instrumented output could change: part of the cache key of instrumented files. */
    public function fingerprint(): string
    {
        $contents = '';

        foreach (self::sources() as $source) {
            $contents .= Filesystem::read($source);
        }

        return sha1($contents . (int) $this->strict . (int) $this->fast);
    }

    /**
     * The files whose code decides the instrumented output and the probe maps.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $sources = [];
        $sources[] = __FILE__;
        $sources[] = __DIR__ . '/InstrumentingVisitor.php';
        $sources[] = __DIR__ . '/SourceEditor.php';
        $sources[] = __DIR__ . '/Edit.php';
        $sources[] = __DIR__ . '/Probe.php';
        $sources[] = __DIR__ . '/Blade/AnalysisCopy.php';
        $sources[] = __DIR__ . '/Blade/LineMap.php';
        $sources[] = __DIR__ . '/Blade/ViewInstrumenter.php';

        return $sources;
    }
}
