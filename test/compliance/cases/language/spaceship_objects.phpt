--TEST--
Language: spaceship (<=>) on objects — zend_compare_objects parity (#3691)
--FILE--
<?php
class A {
    public int $x = 1;
}
$a = new A();
$b = new A();
echo $a <=> $b, "\n";

class B implements Stringable {
    public function __toString(): string { return 'b'; }
}
echo (new B) <=> (new B), "\n";

$c = new A();
$c->x = 2;
echo $a <=> $c, "\n";

class C {
    public int $x = 1;
}
echo (new A) <=> (new C), "\n";
?>
--EXPECT--
0
0
-1
1
