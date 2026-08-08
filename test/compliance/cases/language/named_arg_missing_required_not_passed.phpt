--TEST--
named/unpack missing required → Argument #N ($name) not passed (#29095, zend_execute.c)
--FILE--
<?php
function f($a, $b) {
    echo "$a-$b\n";
}
try {
    f(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
try {
    f(...['b' => 2]);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}

class C {
    public function m($a, $b) {}
    public static function s($a, $b) {}
    public function __invoke($a, $b) {}
}
try {
    (new C)->m(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
try {
    C::s(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
try {
    (new C)(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
$c = function ($a, $b) {};
try {
    $c(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}

try {
    f();
} catch (ArgumentCountError $e) {
    // Strip path/line for stable expect; keep Too few shape.
    echo preg_replace('/ in .* on line \d+/', ' in FILE on line N', $e->getMessage()) . "\n";
}
--EXPECT--
f(): Argument #1 ($a) not passed
f(): Argument #1 ($a) not passed
C::m(): Argument #1 ($a) not passed
C::s(): Argument #1 ($a) not passed
C::__invoke(): Argument #1 ($a) not passed
{closure}(): Argument #1 ($a) not passed
Too few arguments to function f(), 0 passed in FILE on line N and exactly 2 expected
