<?php
class Svc {
    public function __construct(public string $id = '') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn (): Svc => new Svc('proxy'));
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
var_export(class_has_lazy_object_uninitializer(new Svc('eager')));
echo "\n";
$lazy->id;
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
