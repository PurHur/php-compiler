<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionConstant on PHP_COMPILER_PROFILE=8.4 (#16837).
 *
 * php-src: ext/reflection/php_reflection.c — ReflectionConstant (PHP 8.3+).
 */

if (!class_exists('ReflectionConstant', false)) {
    fwrite(STDERR, "fail: ReflectionConstant class missing under forward profile\n");
    exit(1);
}

class C16837
{
    public const FOO = 1;
}

$ref = new ReflectionConstant(C16837::class, 'FOO');
if ('FOO' !== $ref->getName()) {
    fwrite(STDERR, "fail: unexpected constant name\n");
    exit(1);
}
if (1 !== $ref->getValue()) {
    fwrite(STDERR, "fail: unexpected constant value\n");
    exit(1);
}

echo "ok\n";
