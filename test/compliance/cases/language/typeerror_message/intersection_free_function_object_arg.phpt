--TEST--
Language: free-function intersection TypeError uses f(): not arg class::f() (#19526, Zend/zend_execute.c)
--FILE--
<?php
function f(Traversable&Countable $x): void {}

try {
    f(new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function g(int $x): void {}
try {
    g(new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C {
    public function m(int $x): void {}
}
try {
    (new C)->m(new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
f(): Argument #1 ($x) must be of type Traversable&Countable, stdClass given, called in %s on line %d
g(): Argument #1 ($x) must be of type int, stdClass given, called in %s on line %d
C::m(): Argument #1 ($x) must be of type int, stdClass given, called in %s on line %d
