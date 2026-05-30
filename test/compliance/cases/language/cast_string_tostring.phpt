--TEST--
Language: (string) object cast invokes __toString (Zend zend_operators.c, #3421)
--FILE--
<?php
class C {
    public function __toString(): string { return 'ok'; }
}
echo (string) (new C), "\n";

interface StringableLike {
    public function __toString(): string;
}
class Box implements StringableLike {
    public function __toString(): string { return 'box'; }
}
echo (string) (new Box), "\n";

class Base {
    public function __toString(): string { return 'parent'; }
}
class Child extends Base {}
echo (string) (new Child), "\n";
--EXPECT--
ok
box
parent
