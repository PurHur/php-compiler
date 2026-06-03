--TEST--
Language: $this in static method — Error parity (issue #5261)
--FILE--
<?php
class A {
    public static function f() {
        return $this;
    }
}
try {
    A::f();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Using $this when not in object context
