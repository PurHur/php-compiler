<?php
// Issue #25016 — get_mangled_object_vars Reflection + named object:
$r = new ReflectionFunction('get_mangled_object_vars');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), $p->hasType() ? ':'.$p->getType() : '', "\n";
}
class A
{
    private $x = 1;
}
$m = get_mangled_object_vars(object: new A);
echo 'named_ok keys=', count($m), "\n";
