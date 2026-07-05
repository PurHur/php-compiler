<?php

declare(strict_types=1);

class D {
    public function __construct(public private(set) int $x = 1) {}
}

echo "should not run\n";
