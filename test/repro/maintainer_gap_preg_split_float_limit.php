<?php

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

check('preg_split', preg_split('/-/', 'a-b-c-d', 2.9), ['a', 'b-c-d']);

exit($fail === 0 ? 0 : 1);
