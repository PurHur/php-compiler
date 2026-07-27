--TEST--
language: Closure::fromCallable([self::class, private]) TypeError (#23688, zend_closures.c)
--FILE--
<?php
class A {
    private function priv(): void {}
    public function run(): void {
        try {
            Closure::fromCallable([$this, 'priv']);
            echo "instance ok\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        try {
            Closure::fromCallable([self::class, 'priv']);
            echo "static array uncaught\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
(new A())->run();
--EXPECT--
instance ok
TypeError: Failed to create closure from callable: non-static method A::priv() cannot be called statically
