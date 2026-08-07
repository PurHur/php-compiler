<?php
/**
 * #6885 / #28516 — ReflectionClass::createLazy* are phantoms; use newLazyGhost/newLazyProxy.
 */
class C {
    private function __construct() {}
    public string $name = 'unset';
}

var_export(method_exists(ReflectionClass::class, 'createLazyGhost'));
echo "\n";
var_export(method_exists(ReflectionClass::class, 'createLazyProxy'));
echo "\n";

$ref = new ReflectionClass(C::class);
$ghost = $ref->newLazyGhost(function (C $c): void {
    $c->name = 'initialized';
});
echo $ghost->name, "\n";
