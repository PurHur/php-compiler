<?php
// #26320 AOT/JIT smoke — Reflection metadata (+ opendir named arg under VM/JIT).
// Note: full `phpc build` of ReflectionFunction currently fails compile-time with
// "Call to undefined method object::getreturntype()" (pre-existing; not this stub fix).
foreach (['readdir', 'tempnam', 'gethostbynamel', 'sys_getloadavg'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
$r = new ReflectionFunction('opendir');
foreach ($r->getParameters() as $p) {
    echo 'opendir ', $p->getName(), "\n";
}
