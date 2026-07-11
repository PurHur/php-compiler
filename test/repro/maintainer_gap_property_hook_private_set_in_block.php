<?php

declare(strict_types=1);

class C
{
    public string $x {
        get => 'g';
        private(set);
    }
}

$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'bad';
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
