<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn () => new Svc('x'));
echo "before\n";
echo $lazy->id, "\n";
