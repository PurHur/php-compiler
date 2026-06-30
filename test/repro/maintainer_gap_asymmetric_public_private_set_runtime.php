<?php

declare(strict_types=1);

class B {
    public private(set) string $label = 'hi';
}

$b = new B();
echo $b->label, "\n";
try {
    $b->label = 'no';
    echo "write uncaught\n";
    exit(1);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
