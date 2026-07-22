--TEST--
ReflectionMethod::invoke() on private/protected without setAccessible (PHP 8.1+, #22090)
--FILE--
<?php
declare(strict_types=1);

class C {
    private function m(): int {
        return 7;
    }
    protected function n(): int {
        return 3;
    }
}

$rm = new ReflectionMethod(C::class, 'm');
echo $rm->invoke(new C()), "\n";
$rm->setAccessible(false);
echo $rm->invoke(new C()), "\n";

$rn = new ReflectionMethod(C::class, 'n');
echo $rn->invoke(new C()), "\n";
--EXPECT--
7
7
3
