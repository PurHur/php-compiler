--TEST--
Language: inherited typed property Error cites declaring class (prop_info->ce) (#31785, zend_object_handlers.c)
--FILE--
<?php
class A {
    public int $x;
    public static int $s;
    public function __construct() { $this->x = 1; }
}
class B extends A {
    public function __construct() {}
}
try {
    $b = new B;
    echo $b->x, "\n";
    echo "inst_ok\n";
} catch (Error $e) {
    echo "inst=", $e->getMessage(), "\n";
}
try {
    echo B::$s, "\n";
    echo "static_ok\n";
} catch (Error $e) {
    echo "static=", $e->getMessage(), "\n";
}
try {
    $b2 = new B;
    $r = &$b2->x;
    echo "ref_ok\n";
} catch (Error $e) {
    echo "ref=", $e->getMessage(), "\n";
}

class ParentDecl { public int $p; }
class Mid extends ParentDecl {}
class Leaf extends Mid {}
try {
    echo (new Leaf)->p, "\n";
    echo "leaf_ok\n";
} catch (Error $e) {
    echo "leaf=", $e->getMessage(), "\n";
}

class RedeclareParent { public int $x; }
class RedeclareChild extends RedeclareParent { public int $x; }
try {
    echo (new RedeclareChild)->x, "\n";
    echo "redeclare_ok\n";
} catch (Error $e) {
    echo "redeclare=", $e->getMessage(), "\n";
}
--EXPECT--
inst=Typed property A::$x must not be accessed before initialization
static=Typed static property A::$s must not be accessed before initialization
ref=Cannot access uninitialized non-nullable property A::$x by reference
leaf=Typed property ParentDecl::$p must not be accessed before initialization
redeclare=Typed property RedeclareChild::$x must not be accessed before initialization
