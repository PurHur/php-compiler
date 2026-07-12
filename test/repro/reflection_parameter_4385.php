<?php
declare(strict_types=1);

#[Attribute]
final class A {
    public function __construct(public string $x) {}
}

class C {
    public function f(#[A('p')] ?int $p = 42, string ...$rest) {}
}

$rm = new ReflectionMethod(C::class, 'f');
$rp = $rm->getParameters()[0];

echo $rp->getName(), "\n";
echo ($rp->hasType() ? $rp->getType()->__toString() : 'no-type'), "\n";
echo ($rp->isDefaultValueAvailable() ? 'has-default' : 'no-default'), "\n";
if ($rp->isDefaultValueAvailable()) {
    var_export($rp->getDefaultValue());
    echo "\n";
}
$attrs = $rp->getAttributes(A::class);
echo count($attrs), "\n";
if ($attrs) {
    $inst = $attrs[0]->newInstance();
    echo $inst->x, "\n";
}

$rest = $rm->getParameters()[1];
echo $rest->getName(), "\n";
echo $rest->isVariadic() ? "variadic\n" : "not-variadic\n";
echo $rest->isDefaultValueAvailable() ? "rest-has-default\n" : "rest-no-default\n";
