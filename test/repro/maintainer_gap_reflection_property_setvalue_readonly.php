<?php

class C {
    public readonly int $x;

    public function __construct()
    {
        $this->x = 1;
    }
}

$c = new C();
$p = new ReflectionProperty(C::class, 'x');
try {
    $p->setValue($c, 2);
    echo "set_ok\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
echo 'value=', $c->x, "\n";

readonly class D {
    public function __construct(public int $x) {}
}

$d = new D(1);
$p2 = new ReflectionProperty(D::class, 'x');
try {
    $p2->setValue($d, 2);
    echo "promoted_set_ok\n";
} catch (Error $e) {
    echo 'promoted_error: ', $e->getMessage(), "\n";
}
echo 'promoted_value=', $d->x, "\n";

try {
    $c->x = 99;
    echo "direct_set_ok\n";
} catch (Error $e) {
    echo 'direct_error: ', $e->getMessage(), "\n";
}
