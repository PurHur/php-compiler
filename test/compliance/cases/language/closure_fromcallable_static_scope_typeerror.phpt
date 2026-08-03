--TEST--
language: Closure::fromCallable([self::class, private]) from static method is TypeError (#27138, zend_closures.c)
--FILE--
<?php
class A27138 {
    private function priv(): void { echo "called\n"; }
    public static function run(): void {
        try {
            $c = Closure::fromCallable([self::class, 'priv']);
            echo "ok\n";
            $c();
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
A27138::run();
--EXPECT--
TypeError: Failed to create closure from callable: non-static method A27138::priv() cannot be called statically
