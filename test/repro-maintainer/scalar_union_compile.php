<?php

function f(int|string $x): void {
    echo $x, "\n";
}
f(1);

class C {
    public int|string $p;
}
$c = new C();
try {
    var_dump($c->p);
} catch (Error $e) {
    echo get_class($e), "\n";
}
