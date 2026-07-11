<?php

declare(strict_types=1);

class C {
    public private(set) int $x = 1;
}

$c = new C();
echo $c->x, "\n";
try {
    $c->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
