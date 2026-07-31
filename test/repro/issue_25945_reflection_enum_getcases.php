<?php
/**
 * #25945 — ReflectionEnum getCase/getCases + ReflectionClassConstant::isEnumCase
 * (residual after #25940: findClassConstantDecl still used strtolower).
 */
enum E: int
{
    case A = 1;
    case B = 2;
    public const X = 9;
}

$r = new ReflectionEnum(E::class);
echo 'hasA=', $r->hasCase('A') ? 'y' : 'n', "\n";
echo 'has_a=', $r->hasCase('a') ? 'y' : 'n', "\n";

$parts = [];
foreach ($r->getCases() as $c) {
    $parts[] = $c->getName().'='.$c->getBackingValue();
}
echo implode(';', $parts), "\n";
echo 'getCaseA=', $r->getCase('A')->getBackingValue(), "\n";

try {
    $r->getCase('a');
    echo "getCase_a: no throw\n";
} catch (ReflectionException $e) {
    echo 'getCase_a: ', $e->getMessage(), "\n";
}

$rc = new ReflectionClass(E::class);
foreach ($rc->getReflectionConstants() as $c) {
    echo 'RCC ', $c->getName(), ' isEnum=', $c->isEnumCase() ? 'y' : 'n', "\n";
}
