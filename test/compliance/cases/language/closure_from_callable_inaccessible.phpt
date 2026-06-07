--TEST--
language: Closure::fromCallable() inaccessible method TypeError (#7416, zend_closures.c)
--FILE--
<?php
class C {
    private function m(): void {}
    protected function p(): void {}
    private static function sm(): void {}
}
try {
    Closure::fromCallable([new C, 'm']);
    echo "private instance uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    Closure::fromCallable([new C, 'p']);
    echo "protected instance uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    Closure::fromCallable('C::sm');
    echo "private static uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Failed to create closure from callable: cannot access private method C::m()
TypeError: Failed to create closure from callable: cannot access protected method C::p()
TypeError: Failed to create closure from callable: cannot access private method C::sm()
