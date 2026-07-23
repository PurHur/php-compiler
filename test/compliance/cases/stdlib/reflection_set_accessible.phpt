--TEST--
stdlib ReflectionMethod/Property setAccessible(); no isAccessible; Function has neither (#9823, #22512)
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
echo 'RM setAccessible=', method_exists($rm, 'setAccessible') ? 'yes' : 'no', "\n";
echo 'RM isAccessible=', method_exists($rm, 'isAccessible') ? 'yes' : 'no', "\n";
$rm->setAccessible(true);
echo $rm->invoke(new C()), "\n";

$rp = new ReflectionProperty(C::class, 'p');
echo 'RP setAccessible=', method_exists($rp, 'setAccessible') ? 'yes' : 'no', "\n";
echo 'RP isAccessible=', method_exists($rp, 'isAccessible') ? 'yes' : 'no', "\n";
$rp->setAccessible(true);
echo $rp->getValue(new C()), "\n";

$rf = new ReflectionFunction('strlen');
echo 'RF setAccessible=', method_exists($rf, 'setAccessible') ? 'yes' : 'no', "\n";
echo 'RF isAccessible=', method_exists($rf, 'isAccessible') ? 'yes' : 'no', "\n";
--EXPECT--
RM setAccessible=yes
RM isAccessible=no
secret
RP setAccessible=yes
RP isAccessible=no
42
RF setAccessible=no
RF isAccessible=no
