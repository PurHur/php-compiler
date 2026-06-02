<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $object) {
    $object->__construct('x');
});
echo "before\n";
echo $lazy->id, "\n";
