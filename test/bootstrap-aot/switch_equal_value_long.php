<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: switch on hashtable value slot vs int case (TYPE_EQUAL long vs __value__).
 */

function pickFromMap(array $map, string $key): string
{
    switch ($map[$key]) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'other';
    }
}

$out = pickFromMap(['a' => 1, 'b' => 2], 'a');
echo $out, "\n";
