<?php
class C {
    private(get) int $x = 1;
}
$c = new C();
try {
    echo $c->x;
    echo "uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
