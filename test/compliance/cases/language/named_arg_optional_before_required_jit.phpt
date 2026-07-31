--TEST--
JIT: named args skip optional-before-required → ArgumentCountError (#25728, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
function f($a = 1, $b) {
    echo "$a-$b\n";
}
try {
    f(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
f(a: 9, b: 2);
f(1, 2);

function g($a, $b = 2, $c) {
    echo "$a-$b-$c\n";
}
try {
    g(a: 1, c: 9);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}

function h($a, $b = 2, $c = 3) {
    echo "$a-$b-$c\n";
}
h(a: 1, c: 9);
--EXPECT--
f(): Argument #1 ($a) not passed
9-2
1-2
g(): Argument #2 ($b) not passed
1-2-9
