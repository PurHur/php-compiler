<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\VM\Context;

/** Register memcached builtin classes (PECL php-memcached; #6099). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!MemcachedExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        VmMemcached::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
