--TEST--
Language: object strict identity (===) — distinct instances never equal (#3622, Zend zend_operators.c)
--FILE--
<?php
class A {
    public int $x = 1;
}

$a = new A();
$b = new A();
$c = $a;

echo ($a === $b) ? "true\n" : "false\n";
echo ($a === $a) ? "true\n" : "false\n";
echo ($a === $c) ? "true\n" : "false\n";
echo ($a === null) ? "true\n" : "false\n";
echo (null === $a) ? "true\n" : "false\n";
echo ($a !== $b) ? "true\n" : "false\n";
?>
--EXPECT--
false
true
true
false
false
true
