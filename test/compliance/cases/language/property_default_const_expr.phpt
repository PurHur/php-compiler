--TEST--
Property defaults: constant expressions fold at compile time (#5166)
--FILE--
<?php
class C {
    public static int $x = 1 + 2;
    public int $i = 1 + 2;
    public string $s = 'a' . 'b';
    public const A = 10;
    public int $j = self::A + 5;
}
echo C::$x, "\n";
$c = new C();
echo $c->i, "\n";
echo $c->s, "\n";
echo $c->j, "\n";

function f(int $x = 1 + 2): void {
    echo $x, "\n";
}
f();
--EXPECT--
3
3
ab
15
3
