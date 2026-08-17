<?php
// Bare `self` / `parent` typed instance properties must accept valid assignments.
// Contrast: `?self` and `self|string` already match Zend on this tree.

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
    $b->x = $b; // early-bound self => A; B is A
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

// Control: nullable self already works on VM
class N {
    public ?self $x = null;
}
$n = new N;
$n->x = $n;
echo 'nullable_ok ', get_class($n->x), "\n";

// Constructor-promoted bare self (same hole)
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
