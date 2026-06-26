<?php

declare(strict_types=1);

class A {
    public protected(set) string $x = 'ok';
}

echo (new A())->x, "\n";
