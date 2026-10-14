<?php

declare(strict_types=1);

namespace Sonda;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

/**
 * Source-level instrumenter: inserts probe text at byte offsets of the original source,
 * so line numbers never move.
 */
final readonly class Instrumenter
{
    /**
     * @param bool $strict every `if` needs both outcomes tested: an `else` is synthesized when missing
     * @param bool $fast   bind a local reference once per call, so each probe is a single assignment
     */
    public function __construct(
        private bool $strict = false,
        private bool $fast = false,
    ) {
    }

    public function instrument(string $path, string $fileId): InstrumentationResult
    {
        $code   = file_get_contents($path);
        $parser = (new ParserFactory)->createForHostVersion();
        $ast    = $parser->parse($code);

        $visitor = new InstrumentingVisitor($parser->getTokens(), $fileId, $path, $this->strict, $this->fast);
        $visitor->instrumentTopLevel($ast);
        (new NodeTraverser($visitor))->traverse($ast);

        return new InstrumentationResult(SourceEditor::apply($code, $visitor->edits()), $visitor->probes());
    }

    /** Changes whenever the instrumented output could change: part of the cache key of instrumented files. */
    public function fingerprint(): string
    {
        $contents = '';

        foreach (self::sources() as $source) {
            $contents .= file_get_contents($source);
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
        $sources   = [];
        $sources[] = __FILE__;
        $sources[] = __DIR__ . '/InstrumentingVisitor.php';
        $sources[] = __DIR__ . '/SourceEditor.php';
        $sources[] = __DIR__ . '/Edit.php';
        $sources[] = __DIR__ . '/Probe.php';

        return $sources;
    }
}
