<?php
class A {
    public static function f(): string { return static::class; }
}
class B extends A {}
echo B::f(), "\n";
