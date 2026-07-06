<?php

declare(strict_types=1);

/**
 * Maintainer repro: PHP 8.4 `lazy` property modifier (#16813, #16953).
 *
 * php-src: Zend/zend_compile.c ZEND_ACC_LAZY; ext/reflection/php_reflection.c isLazy().
 */

class LazyHolder {
    public lazy string $x = 'hello';
}

$c = new LazyHolder();
$ref = new ReflectionProperty(LazyHolder::class, 'x');
if (!$ref->isLazy($c)) {
    echo "fail: ReflectionProperty::isLazy must be true before first read\n";
    exit(1);
}
echo $c->x, "\n";
if ($ref->isLazy($c)) {
    echo "fail: ReflectionProperty::isLazy must be false after initialization\n";
    exit(1);
}
echo "ok\n";
