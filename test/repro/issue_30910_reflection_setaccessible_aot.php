<?php
/**
 * ReflectionProperty/Method setAccessible + getValue/invoke under AOT (#30910).
 *
 * php-src: ext/reflection/php_reflection.c — setAccessible / getValue / invoke
 */
class A30910
{
    private int $p = 1;

    private function m(int $x): int
    {
        return $x * 2;
    }
}
$prop = (new ReflectionClass(A30910::class))->getProperty('p');
$prop->setAccessible(true);
$o = new A30910();
echo $prop->getValue($o), "\n";
$prop->setValue($o, 9);
echo $prop->getValue($o), "\n";
$m = (new ReflectionClass(A30910::class))->getMethod('m');
$m->setAccessible(true);
var_export($m->invoke($o, 3));
echo "\n";
