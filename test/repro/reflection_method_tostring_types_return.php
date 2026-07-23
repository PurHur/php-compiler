<?php
// Issue #22522 — ReflectionMethod::__toString types/optional/defaults/return (php-src-strict)
class C {
    public function f(int $x, string $y = 'a'): ?array { return null; }
    private static function g(): void {}
}
echo (new ReflectionMethod(C::class, 'f'))->__toString();
echo (new ReflectionMethod(C::class, 'g'))->__toString();
