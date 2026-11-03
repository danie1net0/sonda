<?php

declare(strict_types=1);

namespace Sonda\Cli;

/**
 * The memory_limit the project gives its tests in phpunit.xml (`<php><ini name="memory_limit">`),
 * applied to the sonda process too: it merges the runs of every worker and builds the report, and
 * a large suite needs there what it needs in the workers.
 */
final class MemoryLimit
{
    /** Raises the limit of this process to the given one; never lowers it. */
    public static function raiseTo(?string $limit): void
    {
        if ($limit === null) {
            return;
        }

        $current = ini_parse_quantity(ini_get('memory_limit'));
        $wanted = ini_parse_quantity($limit);

        if ($current === -1 || ($wanted !== -1 && $wanted <= $current)) {
            return;
        }

        ini_set('memory_limit', $limit);
    }
}
