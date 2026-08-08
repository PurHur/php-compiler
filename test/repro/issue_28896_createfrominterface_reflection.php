<?php
/**
 * #28896 — DateTime(Immutable)::createFromInterface Reflection arity/type/named object.
 */
foreach (['DateTime', 'DateTimeImmutable'] as $class) {
    $r = new ReflectionMethod($class, 'createFromInterface');
    echo "$class arity=", $r->getNumberOfParameters();
    echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    $p = $r->getParameters()[0];
    echo ' name=', $p->getName();
    echo ' type=', (string) $p->getType();
    echo PHP_EOL;
}
$src = new DateTimeImmutable('2020-01-01 UTC');
$m = DateTime::createFromInterface(object: $src);
$i = DateTimeImmutable::createFromInterface(object: $m);
echo 'named_dt=', $m->format('c'), PHP_EOL;
echo 'named_dti=', $i->format('c'), PHP_EOL;
