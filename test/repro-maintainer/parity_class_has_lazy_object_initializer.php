<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export(class_has_lazy_object_initializer($lazy));
echo "\n";
var_export(class_has_lazy_object_initializer(new Svc()));
echo "\n";
$lazy2 = $ref->newLazyGhost(function (Svc $o) { $o->id = 'y'; });
$lazy2->id;
var_export(class_has_lazy_object_initializer($lazy2));
echo "\n";
