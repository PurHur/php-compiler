--TEST--
Language: final plain property post-construct write blocked (#22450, #22451, Zend/zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public final string $x = "a";
}
$o = new C;
echo $o->x, "\n";
try {
    $o->x = "b";
    echo "WROTE\n";
} catch (Error $e) {
    echo "BLOCKED:", $e->getMessage(), "\n";
}

class D {
    public final string $y;
    public function __construct() {
        $this->y = "c";
    }
}
$d = new D;
echo $d->y, "\n";
try {
    $d->y = "d";
    echo "WROTE2\n";
} catch (Error $e) {
    echo "BLOCKED2:", $e->getMessage(), "\n";
}
--EXPECT--
a
BLOCKED:Cannot modify final property C::$x
c
BLOCKED2:Cannot modify final property D::$y
