<?php
class C {
    public static int $x = 1 + 2;
    public int $i = 1 + 2;
    public string $s = 'a' . 'b';
}
echo C::$x, "\n";
$c = new C();
echo $c->i, "\n";
echo $c->s, "\n";
