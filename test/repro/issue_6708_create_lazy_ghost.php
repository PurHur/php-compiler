<?php
class C {
    private function __construct() {}
    public string $name = 'unset';
}
$ghost = createLazyGhost(C::class, function (C $c): void {
    $c->name = 'lazy';
});
echo $ghost->name, "\n";
