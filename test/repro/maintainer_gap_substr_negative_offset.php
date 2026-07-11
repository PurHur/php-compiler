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

check('substr(-3)', substr('hello', -3), 'llo');
check('substr(0,-2)', substr('hello', 0, -2), 'hel');
check('substr(-4,2)', substr('abcdef', -4, 2), 'cd');
check('mb_substr(-2)', mb_substr('hello', -2), 'lo');
check('mb_substr(0,-2)', mb_substr('hello', 0, -2), 'hel');

exit($fail === 0 ? 0 : 1);
