<?php
// #28517 — free class_has_lazy_object_* are phantoms; ReflectionClass probes only.
class Svc {
    public function __construct(public string $id = '') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn (): Svc => new Svc('proxy'));
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc('eager')));
echo "\n";
$lazy->id;
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
echo (int) function_exists('class_has_lazy_object_uninitializer'), "\n";
