--TEST--
Language: object loose equality (==) — same-class instances compare equal (#3602, Zend zend_operators.c)
--FILE--
<?php
class A {
    public $x = 1;
}
class B {
    public $x = 1;
}

$a = new A();
$b = new A();
$c = new A();
$c->x = 2;

echo ($a == $b) ? "true\n" : "false\n";
echo ($a === $b) ? "true\n" : "false\n";
echo (new A() == new A()) ? "true\n" : "false\n";
echo ($a == $c) ? "true\n" : "false\n";
echo ($a == new B()) ? "true\n" : "false\n";
?>
--EXPECT--
true
false
true
false
false
