<?php

declare(strict_types=1);

/**
 * ReflectionMethod::createFromMethodName() — issue #7038.
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionMethod_createFromMethodName
 */

class MaintainerRcfm7038
{
    public function go(): string
    {
        return 'ok';
    }
}

echo 'createFromMethodName=', var_export(method_exists('ReflectionMethod', 'createFromMethodName'), true), PHP_EOL;

$r = ReflectionMethod::createFromMethodName(MaintainerRcfm7038::class.'::go');
echo $r->getName(), PHP_EOL;
