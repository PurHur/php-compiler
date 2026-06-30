--TEST--
Language: closure use (&$static) binds parent function static (issue #14077, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

function counter(): Closure
{
    static $n = 0;

    return function () use (&$n): int {
        ++$n;

        return $n;
    };
}

$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});

$c = counter();
$first = $c();
$second = $c();
restore_error_handler();

echo $first, ' ', $second, ' ', $warnings, "\n";
--EXPECT--
1 2 0
