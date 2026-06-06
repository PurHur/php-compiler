<?php
class Base {
    public function make(): self { return $this; }
}
class Child extends Base {
    public function make(): static { return new static(); }
}
echo (new Child())->make()::class, "\n";
