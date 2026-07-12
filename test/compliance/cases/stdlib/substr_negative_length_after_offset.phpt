--TEST--
stdlib substr() negative length after prior 2-arg negative offset (#17598, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

function check(string $label, mixed $got, mixed $expected): void
{
    if ($got !== $expected) {
        echo "FAIL $label\n";
        exit(1);
    }
}

substr('hello', -3);
check('substr(0,-2)', substr('hello', 0, -2), 'hel');
check('substr(-4,2)', substr('abcdef', -4, 2), 'cd');
echo "ok\n";
--EXPECT--
ok
