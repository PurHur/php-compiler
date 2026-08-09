<?php
// #29387 — public readonly private(set) modifier order (Zend 8.4 accepts)
class C {
    public readonly private(set) int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$o = new C(1);
echo $o->x, "\n";
try {
    $o->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
