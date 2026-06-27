<?php

declare(strict_types=1);

class C
{
    public function __construct(private(set) int $x) {}
}

echo "fail: private(set) compiled on reference profile\n";
