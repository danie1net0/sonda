<?php

declare(strict_types=1);

namespace Sonda\Blade;

use Throwable;

use function preg_match, preg_replace, substr, trim;

/**
 * Which line of a Blade template each line of its compiled PHP comes from.
 *
 * Blade keeps the literal text of a template and replaces each construct (directive, echo,
 * component tag) by PHP, sometimes several lines of it, so the compiled line numbers drift. A
 * marker inserted before every construct survives compilation as text: compiling the marked
 * template once more tells, for each compiled line, the line of the construct on it. A compiled
 * line without a marker is literal text or the rest of a multi-line construct: it takes the
 * next template line with the same text, each line once, and the last line mapped otherwise.
 *
 * A component tag gets one marker, at its start, and none inside it: the compiler reads the
 * attributes of the tag, and a marker among them (before `{{ $attributes }}` or `@class`) breaks
 * the tag, which then compiles to other lines.
 *
 * The two compilations are lined up by their text without the markers, not by line number: a
 * construct that a marker still changes moves no line around it. Its own compiled lines take no
 * marker when their count changed, and go line by line when it did not; either way every line
 * maps to a line of the template.
 */
final class LineMap
{
    private const string MARKER = '|---SONDA:%d---|';

    private const string MARKER_PATTERN = '/\|---SONDA:(\d+)---\|/';

    /** The start of an echo, a directive or a tag with a prefix (a Blade component or a Livewire tag). */
    private const string CONSTRUCT_PATTERN = '/@?\{\{|@?\{!!|\B@\w|<\s*\/?\s*[a-zA-Z]\w*[-:][\w\-:.]*/';

    /** The start of an opening component tag. */
    private const string COMPONENT_TAG_PATTERN = '/^<\s*(?:x[-:]|\w+:)/';

    /** The rest of a tag up to its `>`: quoted values, echos and parentheses may hold a `>`. */
    private const string TAG_REST_PATTERN = '/\G(?:"[^"]*"|\'[^\']*\'|\{\{.*?\}\}|\{!!.*?!!\}|(\((?:[^()]++|(?1))*\))|[^>"\'{(]++|[{(])*+>?/s';

    /** How many lines the markers may add or change before the compilations are no longer lined up by text. */
    private const int MAX_EDITS = 500;

    /**
     * @param callable(string): string $compile the Blade compiler, from template to PHP
     *
     * @return array<int, int> compiled line => template line
     */
    public static function of(string $template, string $compiled, callable $compile): array
    {
        $compiledLines = explode("\n", $compiled);
        $markers = self::markers($compiledLines, self::markedCompilation($template, $compile));
        $templateLines = array_map(trim(...), explode("\n", $template));
        $map = [];
        $templateLine = 1;
        $floor = 1;

        foreach ($compiledLines as $index => $line) {
            $number = $index + 1;

            if (isset($markers[$index])) {
                $templateLine = $markers[$index];
                $floor = $templateLine + 1;
                $map[$number] = $templateLine;

                continue;
            }

            if (($found = self::sameTextFrom($templateLines, $floor, trim($line))) !== null) {
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
     * The compiled marked template, line by line; no line when it does not compile.
     *
     * @param callable(string): string $compile
     *
     * @return list<string>
     */
    private static function markedCompilation(string $template, callable $compile): array
    {
        try {
            return explode("\n", $compile(self::mark($template)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The template line that the marker on the matching line of the marked compilation names, for
     * each compiled line that has one.
     *
     * @param list<string> $compiledLines
     * @param list<string> $markedLines
     *
     * @return array<int, int> compiled index => template line
     */
    private static function markers(array $compiledLines, array $markedLines): array
    {
        $unmarkedLines = array_map(static fn (string $line): string => (string) preg_replace(self::MARKER_PATTERN, '', $line), $markedLines);
        $markers = [];

        foreach (self::lineUp($compiledLines, $unmarkedLines) as $compiledIndex => $markedIndex) {
            if (preg_match(self::MARKER_PATTERN, $markedLines[$markedIndex], $match) === 1) {
                $markers[$compiledIndex] = (int) $match[1];
            }
        }

        return $markers;
    }

    /**
     * Which line of the second list each line of the first one is. The lines both share, in
     * order, match; a run of other lines between two shared ones matches line by line when both
     * sides have as many lines, and stays unmatched otherwise.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return array<int, int> index in the first list => index in the second
     */
    private static function lineUp(array $from, array $to): array
    {
        $shared = self::sharedLines($from, $to);
        $shared[count($from)] = count($to);
        $lined = [];
        $previousFrom = -1;
        $previousTo = -1;

        foreach ($shared as $fromIndex => $toIndex) {
            $run = $fromIndex - $previousFrom - 1;

            if ($run === $toIndex - $previousTo - 1) {
                for ($offset = 1; $offset <= $run; $offset++) {
                    $lined[$previousFrom + $offset] = $previousTo + $offset;
                }
            }

            $lined[$fromIndex] = $toIndex;
            $previousFrom = $fromIndex;
            $previousTo = $toIndex;
        }

        unset($lined[count($from)]);

        return $lined;
    }

    /**
     * The lines both lists share, in order, by the shortest edit between them (Myers), as index
     * in the first list => index in the second; none when the edit takes more than MAX_EDITS lines.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return array<int, int>
     */
    private static function sharedLines(array $from, array $to): array
    {
        $trace = self::editTrace($from, $to);
        $shared = [];
        $fromIndex = count($from);
        $toIndex = count($to);

        for ($edits = count($trace) - 1; $edits >= 0; $edits--) {
            $previousDiagonal = self::previousDiagonal($trace[$edits], $fromIndex - $toIndex, $edits);
            $previousFrom = $trace[$edits][$previousDiagonal];
            $previousTo = $previousFrom - $previousDiagonal;

            while ($fromIndex > $previousFrom && $toIndex > $previousTo) {
                $fromIndex--;
                $toIndex--;
                $shared[$fromIndex] = $toIndex;
            }

            $fromIndex = $previousFrom;
            $toIndex = $previousTo;
        }

        ksort($shared);

        return $shared;
    }

    /**
     * How far along the first list the shortest path on each diagonal gets, before each number of
     * edits, up to the edit that reaches the end of both lists; nothing past MAX_EDITS edits.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return list<array<int, int>> diagonal (index in the first list less index in the second) => index in the first list
     */
    private static function editTrace(array $from, array $to): array
    {
        $fromCount = count($from);
        $toCount = count($to);
        $furthest = [1 => 0];
        $trace = [];

        for ($edits = 0; $edits <= self::MAX_EDITS; $edits++) {
            $trace[] = $furthest;

            for ($diagonal = -$edits; $diagonal <= $edits; $diagonal += 2) {
                $previousDiagonal = self::previousDiagonal($furthest, $diagonal, $edits);
                $fromIndex = $furthest[$previousDiagonal] + (int) ($previousDiagonal < $diagonal);
                $toIndex = $fromIndex - $diagonal;

                while ($fromIndex < $fromCount && $toIndex < $toCount && $from[$fromIndex] === $to[$toIndex]) {
                    $fromIndex++;
                    $toIndex++;
                }

                $furthest[$diagonal] = $fromIndex;

                if ($fromIndex >= $fromCount && $toIndex >= $toCount) {
                    return $trace;
                }
            }
        }

        return [];
    }

    /**
     * The diagonal the shortest path to this one comes from, one edit before: from above (a line
     * only in the second list) or from the left (a line only in the first one).
     *
     * @param array<int, int> $furthest
     */
    private static function previousDiagonal(array $furthest, int $diagonal, int $edits): int
    {
        if ($diagonal === -$edits || ($diagonal !== $edits && $furthest[$diagonal - 1] < $furthest[$diagonal + 1])) {
            return $diagonal + 1;
        }

        return $diagonal - 1;
    }

    /**
     * The template with a marker naming the line before each construct outside a component tag, from the
     * end so offsets hold.
     */
    private static function mark(string $template): string
    {
        preg_match_all(self::CONSTRUCT_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        $tagEnd = 0;

        foreach ($matches[0] as [$construct, $offset]) {
            if ($offset < $tagEnd) {
                continue;
            }

            $offsets[$offset] = $offset;
            $tagEnd = self::tagEnd($template, $construct, $offset);
        }

        krsort($offsets);

        foreach ($offsets as $offset) {
            $line = substr_count($template, "\n", 0, $offset) + 1;
            $template = substr($template, 0, $offset) . sprintf(self::MARKER, $line) . substr($template, $offset);
        }

        return $template;
    }

    /**
     * Where the component tag that starts the construct ends, just after its `>`; the start of the
     * construct when it is no opening component tag (`<x-`, `<x:` or a prefix such as `livewire:`).
     * A custom HTML element keeps the markers inside it: Blade compiles it as text.
     */
    private static function tagEnd(string $template, string $construct, int $offset): int
    {
        if (preg_match(self::COMPONENT_TAG_PATTERN, $construct) !== 1) {
            return $offset;
        }

        $restStart = $offset + strlen($construct);
        preg_match(self::TAG_REST_PATTERN, $template, $rest, 0, $restStart);

        return $restStart + strlen($rest[0]);
    }
}
