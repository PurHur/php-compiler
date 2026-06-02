--TEST--
language: static arrow function binds scope, not $this (issue #4363)
--FILE--
<?php
class Base {
    public function make(): Closure {
        return static fn (): string => static::class;
    }
}
class Child extends Base {}

echo (new Child())->make()(), "\n";

class C {
    public function makeThis(): Closure {
        return static fn () => $this;
    }
}
try {
    (new C())->makeThis()();
    echo "no error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Child
Using $this when not in object context

