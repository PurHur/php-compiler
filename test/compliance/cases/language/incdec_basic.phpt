--TEST--
Language: basic ++/-- on variables, properties, statics, offsets (#6321, zend_operators.c)
--FILE--
<?php
$x = 1;
$x++;
echo $x, "\n";

class C {
    public static int $n = 0;
    public int $i = 0;
}

++C::$n;
echo C::$n, "\n";

$c = new C();
echo $c->i++, "\n";
echo $c->i, "\n";

$v = [0];
$v[0]++;
echo $v[0], "\n";
--EXPECT--
2
1
0
1
1
