<?php
// #27741 — ReflectionClass::newLazyGhost Reflection arity + named args (PROFILE≥8.4).
class T
{
    public int $x = 0;
}
$rc = new ReflectionClass(T::class);
$r = new ReflectionMethod(ReflectionClass::class, 'newLazyGhost');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : '?'), ($p->isOptional() ? '=' : ''), PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$o = $rc->newLazyGhost(function (T $obj) {
    $obj->x = 42;
});
echo 'pos=', $o->x, PHP_EOL;
$o2 = $rc->newLazyGhost(initializer: function (T $obj) {
    $obj->x = 7;
});
echo 'named=', $o2->x, PHP_EOL;
