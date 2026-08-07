<?php
/**
 * #6125 / #28516 — reset via resetAsLazyGhost (php-src); resetAsLazyObject is phantom.
 */
class Service {
    public function __construct(public string $id = 'x') {}
}
$ref = new ReflectionClass(Service::class);
$lazy = $ref->newLazyGhost(function (Service $o) {
    $o->__construct('init');
});
$ref->markLazyObjectAsInitialized($lazy);
if (method_exists($ref, 'resetAsLazyObject')) {
    echo "FAIL: resetAsLazyObject phantom still present\n";
    exit(1);
}
$ref->resetAsLazyGhost($lazy, function (Service $o) {
    $o->__construct('init');
});
echo $ref->isUninitializedLazyObject($lazy) ? "OK\n" : "FAIL\n";
echo $lazy->id, "\n";
