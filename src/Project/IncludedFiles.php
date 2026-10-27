<?php

declare(strict_types=1);

namespace Sonda\Project;

use Sonda\Filesystem;

use function in_array, str_ends_with;

/**
 * The PHP files of the scope that the autoloader can never load, for they declare no class,
 * interface, trait or enum by name: a Laravel migration (`return new class`), a routes file, a
 * file of helpers. Code loads them by their path, with include or require.
 */
final class IncludedFiles
{
    private const array DECLARATIONS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function in(array $files): array
    {
        $included = [];

        foreach ($files as $file) {
            if (! str_ends_with($file, '.blade.php') && ! self::declaresANamedType($file)) {
                $included[] = $file;
            }
        }

        return $included;
    }

    private static function declaresANamedType(string $file): bool
    {
        $previous = null;

        foreach (token_get_all(Filesystem::read($file)) as $token) {
            if (! is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // `new class` is anonymous, and `Foo::class` names no declaration.
            if (in_array($token[0], self::DECLARATIONS, true) && $previous !== T_NEW && $previous !== T_DOUBLE_COLON) {
                return true;
            }

            $previous = $token[0];
        }

        return false;
    }
}
