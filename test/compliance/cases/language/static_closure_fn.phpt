--TEST--
language: static closure binds scope, not $this (issue #4363)
--FILE--
<?php
class C {
    public function make(): Closure {
        return static function (): string { return self::class; };
    }
}
echo (new C())->make()(), "\n";

class D {
    public function makeThis(): Closure {
        return static function () { return $this; };
    }
}
try {
    (new D())->makeThis()();
    echo "no error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
C
Using $this when not in object context

