<?php

declare(strict_types=1);

namespace Sonda\Blade;

use Throwable;

use function substr, trim;

/**
 * Which line of a Blade template each line of its compiled PHP comes from.
 *
 * Blade keeps the literal text of a template and replaces each construct (directive, echo,
 * component tag) by PHP, sometimes several lines of it, so the compiled line numbers drift. A
 * marker inserted before every construct survives compilation as text: compiling the marked
 * template once more tells, for each compiled line, the line of the construct on it. A compiled
 * line without a marker is literal text or the rest of a multi-line construct: it takes the
 * next template line with the same text, each line once, and the last line mapped otherwise.
 */
final class LineMap
{
    private const string MARKER = '|---SONDA:%d---|';

    private const string MARKER_PATTERN = '/\|---SONDA:(\d+)---\|/';

    /** The start of an echo, a directive or a tag with a prefix (a Blade component or a Livewire tag). */
    private const string CONSTRUCT_PATTERN = '/@?\{\{|@?\{!!|\B@\w|<\s*\/?\s*[a-zA-Z]\w*[-:][\w\-:.]*/';

    /**
     * @param callable(string): string $compile the Blade compiler, from template to PHP
     *
     * @return array<int, int> compiled line => template line
     */
    public static function of(string $template, string $compiled, callable $compile): array
    {
        $compiledLines = explode("\n", $compiled);
        $markedLines = self::markedCompilation($template, $compile, count($compiledLines));
        $templateLines = array_map(trim(...), explode("\n", $template));
        $map = [];
        $templateLine = 1;
        $floor = 1;

        foreach ($compiledLines as $index => $line) {
            $number = $index + 1;

            if ($markedLines === null) {
                $map[$number] = $number;

                continue;
            }

            if (preg_match(self::MARKER_PATTERN, $markedLines[$index], $match) === 1) {
                $templateLine = (int) $match[1];
                $floor = $templateLine + 1;
            } elseif (($found = self::sameTextFrom($templateLines, $floor, trim($line))) !== null) {
                $templateLine = $found;
                $floor = $found + 1;
            }

            $map[$number] = $templateLine;
        }

        return $map;
    }

    /**
     * The first template line, from one on, with the text given; none for an empty text.
     *
     * @param list<string> $templateLines trimmed
     */
    private static function sameTextFrom(array $templateLines, int $from, string $text): ?int
    {
        if ($text === '') {
            return null;
        }

        foreach (array_slice($templateLines, $from - 1, null, true) as $index => $templateText) {
            if ($templateText === $text) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The compiled marked template, line by line, or null when it cannot be lined up with the
     * plain compilation: the map then falls back to the compiled line numbers themselves.
     *
     * @param callable(string): string $compile
     *
     * @return list<string>|null
     */
    private static function markedCompilation(string $template, callable $compile, int $expectedLines): ?array
    {
        try {
            $lines = explode("\n", $compile(self::mark($template)));
        } catch (Throwable) {
            return null;
        }

        return count($lines) === $expectedLines ? $lines : null;
    }

    /** The template with a marker naming the line before each construct, from the end so offsets hold. */
    private static function mark(string $template): string
    {
        preg_match_all(self::CONSTRUCT_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];

        foreach ($matches[0] as [, $offset]) {
            $offsets[$offset] = $offset;
        }

        krsort($offsets);

        foreach ($offsets as $offset) {
            $line = substr_count($template, "\n", 0, $offset) + 1;
            $template = substr($template, 0, $offset) . sprintf(self::MARKER, $line) . substr($template, $offset);
        }

        return $template;
    }
}
