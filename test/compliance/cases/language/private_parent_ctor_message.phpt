--TEST--
Language: parent::__construct() on private parent ctor — Zend Cannot call private (#25663)
--FILE--
<?php
class A {
    private function __construct() {}
}
class B extends A {
    public function __construct() {
        try {
            parent::__construct();
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
new B();

class C {
    private function __construct() {}
}
class D extends C {
    public function __construct() {
        try {
            C::__construct();
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
new D();
--EXPECT--
Error: Cannot call private A::__construct()
Error: Cannot call private C::__construct()
