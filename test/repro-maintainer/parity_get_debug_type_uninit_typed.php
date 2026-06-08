<?php

declare(strict_types=1);

class C
{
    public int $x;
}

$c = new C();
unset($c->x);

try {
    get_debug_type($c->x);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
