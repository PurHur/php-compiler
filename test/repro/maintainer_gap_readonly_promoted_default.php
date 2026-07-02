<?php

class A {
    public function __construct(public readonly int $x = 1) {}
}

echo (new A())->x, "\n";
