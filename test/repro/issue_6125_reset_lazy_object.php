<?php
class Service {
    public function __construct(public string $id = 'x') {}
}
$ref = new ReflectionClass(Service::class);
$lazy = $ref->newLazyGhost(function (Service $o) {
    $o->__construct('init');
});
$ref->markLazyObjectAsInitialized($lazy);
if (!method_exists($ref, 'resetAsLazyObject')) {
    echo "FAIL: resetAsLazyObject missing\n";
    exit(1);
}
$ref->resetAsLazyObject($lazy);
echo $ref->isUninitializedLazyObject($lazy) ? "OK\n" : "FAIL\n";
echo $lazy->id, "\n";
