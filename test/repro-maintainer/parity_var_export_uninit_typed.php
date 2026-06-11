<?php

class C {
    public int $x;
}

$c = new C;
unset($c->x);
try {
    var_export($c->x);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
