<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
$c = new C();
echo $c->p, "\n";
try {
    $c->p = 'y';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
