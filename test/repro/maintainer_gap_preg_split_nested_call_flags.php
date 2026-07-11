<?php

declare(strict_types=1);

function check(string $label, mixed $got, mixed $expected): void
{
    if ($got !== $expected) {
        echo "FAIL $label\n";
        var_export($got);
        echo "\n";
        exit(1);
    }
}

check(
    'nested',
    preg_split('/( )/', 'a b c', -1, PREG_SPLIT_DELIM_CAPTURE),
    ['a', ' ', 'b', ' ', 'c']
);
echo "ok\n";
exit(0);
