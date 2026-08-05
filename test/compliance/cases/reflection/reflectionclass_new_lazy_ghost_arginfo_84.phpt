--TEST--
ReflectionClass::newLazyGhost Reflection arity/types + named initializer (#27741)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class T {
    public int $x = 0;
}
$rc = new ReflectionClass(T::class);
$r = new ReflectionMethod(ReflectionClass::class, 'newLazyGhost');
echo 'arity=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    $def = '';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        $def = '=' . var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        $def = '=';
    }
    echo $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : '?'), $def, "\n";
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$o = $rc->newLazyGhost(function (T $obj) { $obj->x = 42; });
echo 'pos=', $o->x, "\n";
$o2 = $rc->newLazyGhost(initializer: function (T $obj) { $obj->x = 7; });
echo 'named=', $o2->x, "\n";
$proxy = new ReflectionMethod(ReflectionClass::class, 'newLazyProxy');
echo 'proxy_arity=', $proxy->getNumberOfParameters(), "\n";
echo 'proxy_factory=', $proxy->getParameters()[0]->getName(), "\n";
$reset = new ReflectionMethod(ReflectionClass::class, 'resetAsLazyGhost');
echo 'reset_arity=', $reset->getNumberOfParameters(), "\n";
echo 'reset_ret=', $reset->hasReturnType() ? (string) $reset->getReturnType() : 'none', "\n";
--EXPECT--
arity=2
initializer:callable
options:int=0
ret=object
pos=42
named=7
proxy_arity=2
proxy_factory=factory
reset_arity=3
reset_ret=void
