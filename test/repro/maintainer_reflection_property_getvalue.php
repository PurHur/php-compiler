<?php

declare(strict_types=1);

/**
 * Issue #4469 — ReflectionProperty::getValue()/setValue() parity (ext/reflection/php_reflection.c).
 */

class C
{
    public static int $stat = 10;

    public int $x = 1;
}

$rpS = new ReflectionProperty(C::class, 'stat');
$rpX = new ReflectionProperty(C::class, 'x');

var_dump($rpS->getValue());
var_dump($rpX->getValue(new C()));

try {
    $rpX->getValue();
} catch (Throwable $e) {
    echo 'missing obj: ', get_class($e), "\n";
}

$c = new C();
$rpX->setValue($c, 99);
var_dump($c->x);

$rpS->setValue(55);
var_dump($rpS->getValue());
