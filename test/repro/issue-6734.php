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
