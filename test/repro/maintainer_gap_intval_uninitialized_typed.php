<?php

class C {
    public int $x;
}

$c = new C();
try {
    intval($c->x);
    echo "no throw\n";
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
