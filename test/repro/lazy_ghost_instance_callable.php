<?php

/**
 * Repro #22527 — Zend instance ABI for ReflectionClass::newLazyGhost / newLazyProxy.
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/lazy_ghost_instance_callable.php
 */
class Foo
{
    public int $x = 0;
}

$rc = new ReflectionClass(Foo::class);
$o = $rc->newLazyGhost(function (object $obj) {
    $obj->x = 1;
});
echo $rc->isUninitializedLazyObject($o) ? "uninit\n" : "init\n";
echo $o->x, "\n";
echo $rc->isUninitializedLazyObject($o) ? "uninit\n" : "init\n";

$rc2 = new ReflectionClass(Foo::class);
$p = $rc2->newLazyProxy(function (object $obj) {
    $obj->x = 2;

    return $obj;
});
echo $p->x, "\n";
