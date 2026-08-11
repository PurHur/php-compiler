<?php
/** Issue #30244 — Zend stubs leave $object_or_class untyped for Reflection. */
foreach (['method_exists', 'property_exists'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p0 = $r->getParameters()[0];
    $p1 = $r->getParameters()[1];
    echo $fn, ' p0=', $p0->getName(), ' ty=', $p0->hasType() ? (string) $p0->getType() : '-', "\n";
    echo $fn, ' p1=', $p1->getName(), ' ty=', $p1->hasType() ? (string) $p1->getType() : '-', "\n";
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
