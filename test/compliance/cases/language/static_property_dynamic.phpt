--TEST--
Dynamic static property name — $$ / ${} and VarLikeIdentifier fallback (#3814)
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
echo C::$prop, "\n";
class Counter {
    public static int $n = 0;
}
Counter::$n = 5;
echo Counter::$n, "\n";
--EXPECT--
42
7
7
5
