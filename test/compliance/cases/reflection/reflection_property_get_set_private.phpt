--TEST--
ReflectionProperty::getValue()/setValue() on private/protected without setAccessible (PHP 8.1+, #22091)
--FILE--
<?php
declare(strict_types=1);

class C {
    private int $x = 1;
    protected int $y = 2;
}

$p = new ReflectionProperty(C::class, 'x');
$o = new C();
echo $p->getValue($o), "\n";
$p->setValue($o, 9);
echo $p->getValue($o), "\n";
$p->setAccessible(false);
echo $p->getValue($o), "\n";

$q = new ReflectionProperty(C::class, 'y');
echo $q->getValue($o), "\n";
$q->setValue($o, 4);
echo $q->getValue($o), "\n";
--EXPECT--
1
9
9
2
4
