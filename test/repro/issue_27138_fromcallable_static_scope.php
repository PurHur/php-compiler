<?php
class A {
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
A::run();
