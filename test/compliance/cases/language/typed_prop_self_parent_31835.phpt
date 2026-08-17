--TEST--
Language: bare self/parent typed properties accept valid assigns (#31835, zend_execute / TypeCheck)
--FILE--
<?php
class A {
    public self $x;
}

$a = new A;
try {
    $a->x = $a;
    echo 'self_ok ', get_class($a->x), "\n";
} catch (Throwable $e) {
    echo 'self_fail ', get_class($e), ':', $e->getMessage(), "\n";
}

class B extends A {}
$b = new B;
try {
    $b->x = $b;
    echo 'child_ok ', get_class($b->x), "\n";
} catch (Throwable $e) {
    echo 'child_fail ', get_class($e), ':', $e->getMessage(), "\n";
}

class P {}
class C extends P {
    public parent $x;
}
$c = new C;
try {
    $c->x = new P;
    echo 'parent_ok ', get_class($c->x), "\n";
} catch (Throwable $e) {
    echo 'parent_fail ', get_class($e), ':', $e->getMessage(), "\n";
}

class N {
    public ?self $x = null;
}
$n = new N;
$n->x = $n;
echo 'nullable_ok ', get_class($n->x), "\n";

class Promo {
    public function __construct(public self $x) {}
}
$rp = new ReflectionClass(Promo::class);
$p = $rp->newInstanceWithoutConstructor();
try {
    $p->__construct($p);
    echo 'promoted_ok ', get_class($p->x), "\n";
} catch (Throwable $e) {
    echo 'promoted_fail ', get_class($e), ':', $e->getMessage(), "\n";
}

class Other {}
try {
    $a2 = new A;
    $a2->x = new Other;
    echo "wrong_self_ok\n";
} catch (TypeError $e) {
    echo 'wrong_self=', $e->getMessage(), "\n";
}

try {
    $c2 = new C;
    $c2->x = new stdClass;
    echo "wrong_parent_ok\n";
} catch (TypeError $e) {
    echo 'wrong_parent=', $e->getMessage(), "\n";
}
--EXPECT--
self_ok A
child_ok B
parent_ok P
nullable_ok N
promoted_ok Promo
wrong_self=Cannot assign Other to property A::$x of type self
wrong_parent=Cannot assign stdClass to property C::$x of type parent
