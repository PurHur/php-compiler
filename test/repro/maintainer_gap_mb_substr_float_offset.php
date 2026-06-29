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

check('mb_substr', mb_substr('hello', 1.9, 2.7), 'el');
check('mb_strimwidth', mb_strimwidth('hello world', 1.9, 4.7), 'ello');
check('mb_strcut', mb_strcut('hello', 1.9, 2.7), 'el');
check('mb_strpos', mb_strpos('hello', 'l', 1.9), 2);

exit($fail === 0 ? 0 : 1);
