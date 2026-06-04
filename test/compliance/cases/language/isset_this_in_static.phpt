--TEST--
Language: isset($this) in static method is false without Error (issue #5411)
--FILE--
<?php
class C {
    public static function f() {
        var_dump(isset($this));
    }
}
C::f();
try {
    class D {
        public static function g() {
            return $this;
        }
    }
    D::g();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bool(false)
Error: Using $this when not in object context
