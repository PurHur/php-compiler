<?php
declare(strict_types=1);

class C {
    public private(set) string $x {
        get => 'hi';
    }
}

$c = new C();
try {
    $c->x = 'no';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
