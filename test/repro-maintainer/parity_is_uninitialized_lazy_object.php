<?php
declare(strict_types=1);

class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc()));
echo "\n";
