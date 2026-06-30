<?php

declare(strict_types=1);

function out(string $k, mixed $v): void
{
    echo $k.'='.(is_string($v) ? $v : var_export($v, true))."\n";
}

$ks = ['10' => 1, '2' => 2];
krsort($ks, SORT_NUMERIC);
out('array_reverse', array_reverse(['a' => 1, 'b' => 2], true));
out('array_slice', array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 2, true));
