--TEST--
language: Closure::fromCallable([self::class, private]) binds $this (#23771, zend_closures.c)
--FILE--
<?php
class A {
    private function priv(): void { echo "called\n"; }
    public function run(): void {
        try {
            Closure::fromCallable([$this, 'priv']);
            echo "instance ok\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        try {
            $c = Closure::fromCallable([self::class, 'priv']);
            echo "static array ok\n";
            $c();
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
(new A())->run();
--EXPECT--
instance ok
static array ok
called
