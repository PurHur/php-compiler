<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionClass::getLazyPropertyNames() for PHP 8.4 lazy modifier (#16954).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getLazyPropertyNames
 */

class C {
    public lazy string $a = '1';
    public string $b = '2';
}

$r = new ReflectionClass(C::class);
$names = $r->getLazyPropertyNames();
sort($names);
echo implode(',', $names), "\n";
echo "ok\n";
