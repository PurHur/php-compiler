--TEST--
Language: interface `: self` return — implementing class may use `: static` (#6734, zend_inheritance.c)
--FILE--
<?php
interface I {
    public function make(): self;
}
class C implements I {
    public function make(): static {
        return new static();
    }
}
echo (new C())->make()::class, "\n";
--EXPECT--
C
