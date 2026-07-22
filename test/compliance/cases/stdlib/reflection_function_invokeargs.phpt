--TEST--
stdlib ReflectionFunction::invokeArgs() (#22088, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function add($a, $b) {
    return $a + $b;
}
function double($a) {
    return $a * 2;
}

$rf = new ReflectionFunction('add');
echo $rf->invokeArgs([2, 3]), "\n";
$rd = new ReflectionFunction('double');
echo $rd->invoke(4), "\n";

try {
    $rf->invokeArgs();
} catch (ArgumentCountError $e) {
    echo 'argc:', $e->getMessage(), "\n";
}
try {
    $rf->invokeArgs(1);
} catch (TypeError $e) {
    echo 'type:', $e->getMessage(), "\n";
}
--EXPECT--
5
8
argc:ReflectionFunction::invokeArgs() expects exactly 1 argument, 0 given
type:ReflectionFunction::invokeArgs(): Argument #1 ($args) must be of type array, int given
