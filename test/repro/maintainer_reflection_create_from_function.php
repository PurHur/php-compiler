<?php

declare(strict_types=1);

/**
 * ReflectionFunction::createFromFunction() — issue #6994.
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionFunction_createFromFunction
 */

function maintainer_rf6994(int $x): int
{
    return $x;
}

echo 'createFromFunction=', var_export(method_exists('ReflectionFunction', 'createFromFunction'), true), PHP_EOL;

$r = ReflectionFunction::createFromFunction('maintainer_rf6994');
echo $r->getName(), PHP_EOL;
echo count($r->getParameters()), PHP_EOL;
