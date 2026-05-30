--TEST--
Language: new static() late-bound instantiation (issue #3412)
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
