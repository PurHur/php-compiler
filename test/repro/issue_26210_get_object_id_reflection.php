<?php
// Repro #26210 — get_object_id Reflection object→int + named object: (PROFILE=8.4)
$o = new stdClass;
$id = get_object_id($o);
echo 'positional=', $id, "\n";
echo 'named=', get_object_id(object: $o), "\n";
$r = new ReflectionFunction('get_object_id');
echo 'arity=', $r->getNumberOfParameters(), ' ret=', $r->getReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
foreach ($r->getParameters() as $p) {
    echo 'param=', $p->getName(), ' type=', $p->getType() ? (string) $p->getType() : '(none)', "\n";
}
