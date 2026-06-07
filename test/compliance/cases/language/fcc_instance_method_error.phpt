--TEST--
Language: first-class callable on instance method must Error (issue #7465, zend_compile.c)
--FILE--
<?php
class C {
    public function m(): int { return 1; }
}
try {
    $f = C::m(...);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class S {
    public static function m(): int { return 2; }
}
echo S::m(...)(), "\n";
--EXPECT--
Error: Non-static method C::m() cannot be called statically
2
