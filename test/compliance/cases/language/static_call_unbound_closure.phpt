--TEST--
Language: static:: call from unbound closure — Zend no class scope message (#5434, zend_execute.c)
--FILE--
<?php
class C {
    public static function m(): void {}
}
$f = function (): void {
    C::m();
    static::m();
};
try {
    $f();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
--EXPECT--
Error: Cannot access "static" when no class scope is active
