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

check('strpos', strpos('hello', 'l', 2.7), 2);
check('stripos', stripos('Hello', 'L', 1.9), 2);
check('strrpos', strrpos('hello', 'l', 2.9), 3);
check('strripos', strripos('Hello', 'L', 1.9), 3);

exit($fail === 0 ? 0 : 1);
