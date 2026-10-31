<?php

declare(strict_types=1);

namespace Sonda\Blade;

use PhpToken;
use Sonda\{Edit, SourceEditor};

use function max, preg_replace, str_starts_with, substr;

/**
 * The copy of a compiled view that php-code-coverage analyses in place of the template: the same
 * lines, so the line map holds, shaped as PHP that tells the report what the template cannot.
 *
 * - The whole view becomes the function `view`, the unit the report counts branches by: the probes
 *   of the view carry that name, and php-code-coverage counts the branches of declared functions only.
 * - `use` statements, which cannot sit inside a function, are blanked; they run no code.
 * - Inline HTML ending in a newline gets `<?php ?>` before it, so the next line is not taken as
 *   part of the HTML statement: an `@endif` line stays non-executable instead of never covered.
 * - The copy has at least as many lines as the template, so the function spans every template line.
 */
final class AnalysisCopy
{
    private const string OPENING = '<?php function ' . ViewInstrumenter::FUNCTION . '() { ?>';

    public static function of(string $compiled, string $template): string
    {
        $tokens = PhpToken::tokenize($compiled);
        $edits = [];
        $depth = 0;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->is(T_INLINE_HTML)) {
                $newline = self::trailingNewline($token->text);

                if ($newline !== '') {
                    $edits[] = Edit::opening($token->pos + strlen($token->text) - strlen($newline), '<?php ?>');
                }
            } elseif ($token->is(T_USE) && $depth === 0 && ! self::closesOver($tokens, $index)) {
                $index = self::blank($compiled, $tokens, $index, $edits);
            } elseif ($token->is(['{', T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;
            }
        }

        $code = self::padded(SourceEditor::apply($compiled, $edits), $template);
        $inHtml = $tokens[count($tokens) - 1]->is([T_INLINE_HTML, T_CLOSE_TAG]);

        return self::opening($code) . self::closed($code, $inHtml ? '<?php } ?>' : ' }');
    }

    /**
     * `function () use ($x)` is a closure capturing variables, not a statement.
     *
     * @param array<PhpToken> $tokens
     */
    private static function closesOver(array $tokens, int $index): bool
    {
        do {
            $index--;
        } while ($index > 0 && $tokens[$index]->isIgnorable());

        return $tokens[$index]->is(')');
    }

    /**
     * Blanks a `use` statement up to its `;` (or the closing tag that ends it), keeping its newlines.
     *
     * @param array<PhpToken> $tokens
     * @param list<Edit>      $edits
     *
     * @return int the index of the token that ends the statement
     */
    private static function blank(string $code, array $tokens, int $index, array &$edits): int
    {
        $start = $tokens[$index]->pos;
        $depth = 0;

        while (($depth !== 0 || ! $tokens[$index]->is(';')) && ! $tokens[$index]->is(T_CLOSE_TAG)) {
            $depth += $tokens[$index]->is('{') ? 1 : ($tokens[$index]->is('}') ? -1 : 0);
            $index++;
        }

        $end = $tokens[$index]->is(';') ? $tokens[$index]->pos + 1 : $tokens[$index]->pos;
        $edits[] = Edit::opening($start, (string) preg_replace('/[^\r\n]/', ' ', substr($code, $start, $end - $start)), $end - $start);

        return $index;
    }

    /** The newline that ends the text: `\r\n`, `\n`, or none. */
    private static function trailingNewline(string $text): string
    {
        if (str_ends_with($text, "\r\n")) {
            return "\r\n";
        }

        return str_ends_with($text, "\n") ? "\n" : '';
    }

    /** Newlines appended until the code has the lines of the template. */
    private static function padded(string $code, string $template): string
    {
        $missing = substr_count($template, "\n") - substr_count($code, "\n");

        return $code . str_repeat("\n", max(0, $missing));
    }

    /** The function opening, with one more newline when the code starts with one: `?>` eats it. */
    private static function opening(string $code): string
    {
        return self::OPENING . (str_starts_with($code, "\n") || str_starts_with($code, "\r\n") ? "\n" : '');
    }

    /** The code with the function closed before its final newline, so no line is added. */
    private static function closed(string $code, string $closing): string
    {
        $newline = self::trailingNewline($code);

        return substr($code, 0, strlen($code) - strlen($newline)) . $closing . $newline;
    }
}
