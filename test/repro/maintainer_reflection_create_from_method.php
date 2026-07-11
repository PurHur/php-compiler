<?php

declare(strict_types=1);

/**
 * ReflectionMethod::createFromMethodName() — issue #7038 / profile gate #16724.
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionMethod_createFromMethodName
 */

echo 'createFromMethodName=', var_export(method_exists('ReflectionMethod', 'createFromMethodName'), true), PHP_EOL;
