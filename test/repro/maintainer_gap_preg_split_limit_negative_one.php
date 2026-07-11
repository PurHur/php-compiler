<?php

declare(strict_types=1);

$fail = 0;

function check(string $label, mixed $got, mixed $expected): void
{
    global $fail;
    if ($got !== $expected) {
        echo "FAIL $label: got ";
        var_export($got);
        echo " expected ";
        var_export($expected);
        echo "\n";
        ++$fail;
    }
}

check('preg_split(-1)', preg_split('/ /', 'a b c', -1), ['a', 'b', 'c']);
check('preg_split(-1 delim)', preg_split('/( )/', 'a b c', -1, PREG_SPLIT_DELIM_CAPTURE), ['a', ' ', 'b', ' ', 'c']);
check('preg_split(-1 /a/)', preg_split('/a/', 'bab', -1), ['b', 'b']);

exit($fail === 0 ? 0 : 1);
