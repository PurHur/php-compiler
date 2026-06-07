<?php
// Issue #7245: clone readonly class — nested offset write must Error (zend_readonly.c)
readonly class C {
    public function __construct(public array $a) {}
}
$c = new C([1]);
$d = clone $c;
try {
    $d->a[0] = 2;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export([$c->a, $d->a]);
