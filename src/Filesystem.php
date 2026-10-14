<?php

declare(strict_types=1);

namespace Sonda;

use RuntimeException;

final class Filesystem
{
    public static function read(string $path): string
    {
        // Reading a missing path would warn: the check keeps the failure to the exception.
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("O arquivo {$path} não pôde ser lido.");
        }

        return $contents;
    }
}
