--TEST--
By-ref call arguments reject non-referenceable expressions (issue #5369, zend_execute.c)
--FILE--
<?php
function f(&$x) {}
try {
    f(1);
    echo "user ok\n";
} catch (Throwable $e) {
    echo "user: " . $e->getMessage() . "\n";
}

try {
    f($y = 1);
    echo "assign ok\n";
} catch (Throwable $e) {
    echo "assign: " . $e->getMessage() . "\n";
}

try {
    sort([1, 2]);
    echo "sort ok\n";
} catch (Throwable $e) {
    echo "sort: " . $e->getMessage() . "\n";
}

function g(array &$a) {}
try {
    g([]);
    echo "array ok\n";
} catch (Throwable $e) {
    echo "array: " . $e->getMessage() . "\n";
}

$a = 1;
f($a);
echo $a, "\n";
$arr = [1, 2];
sort($arr);
echo implode(',', $arr), "\n";
--EXPECT--
user: f(): Argument #1 ($x) could not be passed by reference
assign: f(): Argument #1 ($x) could not be passed by reference
sort: sort(): Argument #1 ($array) could not be passed by reference
array: g(): Argument #1 ($a) could not be passed by reference
1
1,2
--CREDITS--
PurHur/php-compiler issue #5369
