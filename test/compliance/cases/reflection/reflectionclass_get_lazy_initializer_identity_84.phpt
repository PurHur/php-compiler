--TEST--
ReflectionClass::getLazyInitializer() returns the same Closure as newLazyGhost/newLazyProxy (#29152)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public int $x = 1;
}
$r = new ReflectionClass(C::class);

$ghostInit = function (C $obj) { $obj->x = 4; };
$ghost = $r->newLazyGhost($ghostInit);
$g = $r->getLazyInitializer($ghost);
echo $g === $ghostInit ? "ghost-same\n" : "ghost-diff\n";

$proxyInit = function (C $obj) { $obj->x = 7; return $obj; };
$proxy = $r->newLazyProxy($proxyInit);
$p = $r->getLazyInitializer($proxy);
echo $p === $proxyInit ? "proxy-same\n" : "proxy-diff\n";

$resetInit = function (C $obj) { $obj->x = 9; };
$r->markLazyObjectAsInitialized($ghost);
$r->resetAsLazyGhost($ghost, $resetInit);
$rg = $r->getLazyInitializer($ghost);
echo $rg === $resetInit ? "reset-same\n" : "reset-diff\n";
--EXPECT--
ghost-same
proxy-same
reset-same
