<?php

declare(strict_types=1);

namespace CovProto;

use function substr;

final class SourceEditor
{
    /** @param list<Edit> $edits */
    public static function apply(string $code, array $edits): string
    {
        usort($edits, Edit::compare(...));

        $output   = '';
        $position = 0;

        foreach ($edits as $edit) {
            $output  .= substr($code, $position, $edit->position - $position) . $edit->text;
            $position = $edit->position + $edit->deleteLength;
        }

        return $output . substr($code, $position);
    }
}
