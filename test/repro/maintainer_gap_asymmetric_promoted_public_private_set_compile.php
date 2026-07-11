<?php

declare(strict_types=1);

class D {
    public function __construct(public private(set) int $x = 1) {}
}

$d = new D();
echo $d->x, "\n";
try {
    $d->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
