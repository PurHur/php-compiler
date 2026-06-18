<?php
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

class D {
    public protected(set) int $y = 3;
}
$d = new D();
echo $d->y, "\n";
try {
    $d->y = 4;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
