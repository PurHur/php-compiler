<?php
class C {
    use LazyGhostTrait;
    public string $name = 'init';
}
$c = C::createLazyGhost(function (C $o): void {
    $o->name = 'lazy';
});
var_dump($c->name);

$ghost = C::createLazyGhost(function (C $o): void {
    $o->name = 'never';
});
$ghost->markLazyObjectAsInitialized();
var_dump($ghost->name);
