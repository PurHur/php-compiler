<?php
declare(strict_types=1);

class A {
    public (private(set)) string $x = 'hi';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'no';
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
