--TEST--
Language: private call from anonymous subclass — Error message scope A@anonymous not NUL+file (#26031, zend_object_handlers.c)
--FILE--
<?php
class A {
    private function f() { return 1; }
    private static function sf() { return 2; }
    public function makeInstance() {
        return new class extends A {
            public function g() { return $this->f(); }
        };
    }
    public function makeStatic() {
        return new class extends A {
            public function g() { return self::sf(); }
        };
    }
}
foreach (['makeInstance', 'makeStatic'] as $maker) {
    try {
        (new A)->$maker()->g();
        echo "no throw\n";
    } catch (Throwable $e) {
        $m = $e->getMessage();
        echo "has_nul=", (strpos($m, "\0") !== false ? "1" : "0"), "\n";
        echo "msg=", $m, "\n";
    }
}
--EXPECT--
has_nul=0
msg=Call to private method A::f() from scope A@anonymous
has_nul=0
msg=Call to private method A::sf() from scope A@anonymous
