--TEST--
AOT: new static() and : static return (issue #4792)
--FILE--
<?php
class B {
    public static function make(): static {
        return new static();
    }
    public function tag(): string {
        return static::class;
    }
}
class C extends B {}
echo (new C())->make()->tag();
--EXPECT--
C
