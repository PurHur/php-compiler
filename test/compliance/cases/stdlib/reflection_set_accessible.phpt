--TEST--
stdlib ReflectionMethod/Property/Function setAccessible() API (#9823)
--FILE--
<?php
declare(strict_types=1);

class C {
    private function m(): string {
        return 'secret';
    }
    private int $p = 42;
}

$rm = new ReflectionMethod(C::class, 'm');
echo method_exists($rm, 'setAccessible') ? 'method-setAccessible' : 'missing-method-setAccessible', "\n";
echo $rm->isAccessible() ? 'method-inaccessible' : 'method-not-accessible', "\n";
$rm->setAccessible(true);
echo $rm->isAccessible() ? 'method-accessible' : 'method-still-inaccessible', "\n";
echo $rm->invoke(new C()), "\n";

$rp = new ReflectionProperty(C::class, 'p');
echo method_exists($rp, 'setAccessible') ? 'property-setAccessible' : 'missing-property-setAccessible', "\n";
echo $rp->isAccessible() ? 'property-inaccessible' : 'property-not-accessible', "\n";
$rp->setAccessible(true);
echo $rp->isAccessible() ? 'property-accessible' : 'property-still-inaccessible', "\n";
echo $rp->getValue(new C()), "\n";

$rf = new ReflectionFunction('strlen');
echo method_exists($rf, 'setAccessible') ? 'function-setAccessible' : 'missing-function-setAccessible', "\n";
echo $rf->isAccessible() ? 'function-accessible' : 'function-inaccessible', "\n";
--EXPECT--
method-setAccessible
method-not-accessible
method-accessible
secret
property-setAccessible
property-not-accessible
property-accessible
42
function-setAccessible
function-accessible
