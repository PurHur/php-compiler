<?php
class C {
    private function __construct() {}
    public string $name = 'unset';
}
$ghost = (new ReflectionClass(C::class))->newLazyGhost(function (C $c): void {
    $c->name = 'lazy';
});
echo $ghost->name, "\n";
