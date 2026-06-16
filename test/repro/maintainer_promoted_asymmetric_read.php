<?php
class D {
    public function __construct(private(get) int $x = 1) {}
}
$d = new D();
try {
    echo $d->x;
    echo " uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
