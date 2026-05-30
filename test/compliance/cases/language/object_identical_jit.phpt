--TEST--
Language: object strict identity (===) JIT — handle compare (#3622, Zend zend_operators.c)
--FILE--
<?php
class A {
    public int $x = 1;
}

$a = new A();
$b = new A();

echo ($a === $b) ? "same\n" : "distinct\n";
echo ($a === $a) ? "self\n" : "other\n";
?>
--EXPECT--
distinct
self
