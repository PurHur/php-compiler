--TEST--
Language: static call to non-static method throws Error (issue #5339)
--FILE--
<?php
class C {
    public function f(): void {
        echo "ok\n";
    }
}
try {
    C::f();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class Base {
    public function g(): void {
        echo get_called_class(), "\n";
    }
}
class Child extends Base {}
try {
    Child::g();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Non-static method C::f() cannot be called statically
Error: Non-static method Base::g() cannot be called statically
