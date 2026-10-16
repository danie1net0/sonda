<?php

declare(strict_types=1);

namespace Sonda\Git;

/** The PHP files changed since a reference: committed since the merge base, modified or new in the working tree. */
final class ChangedFiles
{
    /** @return list<string> real paths, sorted */
    public static function since(string $repository, string $reference): array
    {
        return array_keys(ChangedLines::since($repository, $reference));
    }
}
