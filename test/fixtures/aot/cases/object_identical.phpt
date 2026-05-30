--TEST--
AOT: object strict identity (===) (#3622)
--FILE--
<?php
class A {
    public int $x = 1;
}

$a = new A();
$b = new A();
$c = $a;

echo ($a === $b) ? "0\n" : "1\n";
echo ($a === $c) ? "1\n" : "0\n";
echo ($a !== $b) ? "1\n" : "0\n";
--EXPECT--
1
1
1
