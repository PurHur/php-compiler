<?php

declare(strict_types=1);

// #35196 — file-scope const = new UserClass (PHP 8.1+ new-in-initializers)
class A
{
    public function __construct(public int $x = 1) {}
}

const C = new A(2);
echo C->x, "\n";
