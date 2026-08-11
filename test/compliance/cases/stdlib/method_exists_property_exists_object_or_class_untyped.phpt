--TEST--
method_exists/property_exists Reflection $object_or_class untyped (#30244)
--FILE--
<?php
foreach (['method_exists', 'property_exists'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p0 = $r->getParameters()[0];
    $p1 = $r->getParameters()[1];
    echo $fn, ' p0=', $p0->getName(), ' ty=', $p0->hasType() ? (string) $p0->getType() : '-', "\n";
    echo $fn, ' p1=', $p1->getName(), ' ty=', $p1->hasType() ? (string) $p1->getType() : '-', "\n";
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
?>
--EXPECT--
method_exists p0=object_or_class ty=-
method_exists p1=method ty=string
method_exists ret=bool
property_exists p0=object_or_class ty=-
property_exists p1=property ty=string
property_exists ret=bool
