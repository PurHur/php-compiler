<?php
// Repro #34130 — ReflectionClass::setStaticPropertyValue thin AOT
class SspvBase
{
    public static $a = 1;
}

class SspvChild extends SspvBase
{
    public static $b = 2;
}

$r = new ReflectionClass(SspvChild::class);
$r->setStaticPropertyValue('a', 9);
$r->setStaticPropertyValue('b', 8);
echo SspvChild::$a, '|', SspvChild::$b, '|';
echo $r->getStaticPropertyValue('a'), '|', $r->getStaticPropertyValue('b'), "\n";
