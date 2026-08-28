<?php

declare(strict_types=1);

/**
 * AOT: ReflectionParameter::isVariadic() on internal builtins (#24461 peer #23593).
 * php-src: ext/reflection/php_reflection.c zim_ReflectionParameter_isVariadic
 */
echo (new ReflectionParameter('array_diff', 0))->isVariadic() ? '1' : '0', "\n";
echo (new ReflectionParameter('array_diff', 1))->isVariadic() ? '1' : '0', "\n";
echo (new ReflectionParameter('strlen', 0))->isVariadic() ? '1' : '0', "\n";
