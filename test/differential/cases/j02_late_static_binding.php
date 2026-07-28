<?php
// Ordinary PHP: `new static()` and `static::class` through a subclass.
class A { public static function make(): static { return new static(); } public function who(): string { return static::class; } }
class B extends A {}
echo (B::make())->who(), "\n";
