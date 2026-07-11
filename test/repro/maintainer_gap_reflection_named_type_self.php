<?php

declare(strict_types=1);

class C {
    public function m(self $x): self {}
}

$rm = new ReflectionMethod(C::class, 'm');
$paramType = $rm->getParameters()[0]->getType();
$returnType = $rm->getReturnType();

$ok = true;
if ('self' !== $paramType->getName()) {
    echo 'fail: param getName='.var_export($paramType->getName(), true)."\n";
    $ok = false;
}
if ($paramType->isBuiltin()) {
    echo "fail: param isBuiltin=true\n";
    $ok = false;
}
if ('self' !== $returnType->getName()) {
    echo 'fail: return getName='.var_export($returnType->getName(), true)."\n";
    $ok = false;
}
if ($returnType->isBuiltin()) {
    echo "fail: return isBuiltin=true\n";
    $ok = false;
}

if ($ok) {
    echo "ok\n";
}
