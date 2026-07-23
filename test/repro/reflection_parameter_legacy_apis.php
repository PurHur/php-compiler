<?php
/**
 * ReflectionParameter isArray/isCallable/getClass/getDeclaringFunction/__toString (#22408)
 */
function f(array $a, callable $c, stdClass $o) {}
class C
{
    public function m(stdClass $x): void
    {
    }
}

$ps = (new ReflectionFunction('f'))->getParameters();
foreach ($ps as $p) {
    echo $p->getName(), "\n";
    echo '  isArray=', var_export($p->isArray(), true), "\n";
    echo '  isCallable=', var_export($p->isCallable(), true), "\n";
    echo '  decl=', $p->getDeclaringFunction()->getName(), "\n";
    echo '  declCls=', ($p->getDeclaringClass()?->getName() ?? 'null'), "\n";
    echo '  class=', ($p->getClass()?->getName() ?? 'null'), "\n";
    echo '  str=', (string) $p, "\n";
}

$pm = (new ReflectionMethod(C::class, 'm'))->getParameters()[0];
echo "m.x\n";
echo '  decl=', $pm->getDeclaringFunction()->getName(), ' ', get_class($pm->getDeclaringFunction()), "\n";
echo '  declCls=', $pm->getDeclaringClass()->getName(), "\n";
echo '  str=', (string) $pm, "\n";
