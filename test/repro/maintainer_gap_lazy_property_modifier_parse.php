<?php

declare(strict_types=1);

/**
 * Maintainer repro: PHP 8.4 `lazy` property modifier (#16813).
 *
 * php-src: Zend/zend_compile.c ZEND_ACC_LAZY.
 */

class LazyHolder {
    public lazy string $x = 'hello';
}

$c = new LazyHolder();
$ref = new ReflectionProperty(LazyHolder::class, 'x');
echo $ref->isLazy($c) ? "lazy\n" : "not-lazy\n";
echo $c->x, "\n";
echo $ref->isLazy($c) ? "lazy-after\n" : "initialized\n";
echo "ok\n";
