--TEST--
Language: unset() uninitialized readonly in __construct then reinit (#29131, zend_object_handlers.c)
--FILE--
<?php
class A {
    public readonly int $x;
    public function __construct() {
        unset($this->x);
        $this->x = 1;
    }
}
class B {
    public readonly int $x;
    public function __construct() {
        $this->x = 0;
        unset($this->x);
        $this->x = 1;
    }
}
class C {
    public readonly int $x;
    public function __construct() {}
    public function init(): void {
        unset($this->x);
        $this->x = 2;
    }
}
class ParentR {
    public readonly int $x;
}
class ChildR extends ParentR {
    public function __construct() {
        unset($this->x);
        $this->x = 9;
    }
}

try {
    $a = new A;
    echo 'ctor_uninit_unset:', $a->x, "\n";
} catch (Throwable $e) {
    echo 'ctor_uninit_unset:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $b = new B;
    echo 'ctor_init_then_unset:', $b->x, "\n";
} catch (Throwable $e) {
    echo 'ctor_init_then_unset:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $c = new C;
    $c->init();
    echo 'method_uninit_unset:', $c->x, "\n";
} catch (Throwable $e) {
    echo 'method_uninit_unset:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $a2 = new A;
    unset($a2->x);
    echo "post_ctor_unset_ok\n";
} catch (Throwable $e) {
    echo 'post_ctor_unset:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $ch = new ChildR;
    echo 'child_ctor:', $ch->x, "\n";
} catch (Throwable $e) {
    echo 'child_ctor:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ctor_uninit_unset:1
ctor_init_then_unset:Error:Cannot unset readonly property B::$x
method_uninit_unset:2
post_ctor_unset:Error:Cannot unset readonly property A::$x
child_ctor:Error:Cannot unset readonly property ParentR::$x from scope ChildR
