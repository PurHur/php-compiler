<?php

declare(strict_types=1);

/**
 * ReflectionFunction::createFromFunction() — issue #6994 / profile gate #16724.
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionFunction_createFromFunction
 */

echo 'createFromFunction=', var_export(method_exists('ReflectionFunction', 'createFromFunction'), true), PHP_EOL;
