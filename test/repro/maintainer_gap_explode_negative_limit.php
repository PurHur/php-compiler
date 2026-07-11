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

check('explode(-1)', explode('a', 'bab', -1), ['b']);
check('explode(-2)', explode('-', 'a-b-c-d', -2), ['a', 'b']);

exit($fail === 0 ? 0 : 1);
