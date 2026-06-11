<?php

class C {
    private function __construct() {}
    public string $name = 'unset';
}

var_export(method_exists(ReflectionClass::class, 'createLazyGhost'));
echo "\n";
var_export(method_exists(ReflectionClass::class, 'createLazyProxy'));
echo "\n";

$ghost = ReflectionClass::createLazyGhost(C::class, function (C $c): void {
    $c->name = 'initialized';
});
echo $ghost->name, "\n";
