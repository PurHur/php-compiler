<?php
/**
 * Maintainer repro: ReflectionClassConstant::hasType() (#17359).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_class_constant_has_type()
 */
declare(strict_types=1);

class C17359 {
    public const int N = 42;
    public const PLAIN = 1;
}

$typed = new ReflectionClassConstant(C17359::class, 'N');
$plain = new ReflectionClassConstant(C17359::class, 'PLAIN');

echo ($typed->hasType() ? 'typed' : 'untyped'), "\n";
echo ($plain->hasType() ? 'typed' : 'untyped'), "\n";
