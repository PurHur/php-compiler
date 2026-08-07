<?php
// #28517 — free class_has_lazy_object_* are phantoms; ReflectionClass probes only.
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc()));
echo "\n";
$lazy->id;
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
echo (int) function_exists('class_has_lazy_object_initializer'), "\n";
