--TEST--
Dynamic static property name — $$ / ${} only (Zend; #3814/#23606)
--FILE--
<?php
class C {
    public static int $x = 42;
}
$n = 'x';
echo C::$$n, "\n";
$p = 'x';
C::${$p} = 7;
echo C::$$p, "\n";
$prop = 'x';
try {
    echo C::$prop, "\n";
} catch (Throwable $e) {
    echo get_class($e), "|", $e->getMessage(), "\n";
}
class Counter {
    public static int $n = 0;
}
Counter::$n = 5;
echo Counter::$n, "\n";
--EXPECT--
42
7
Error|Access to undeclared static property C::$prop
5
