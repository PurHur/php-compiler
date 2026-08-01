<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\VM\Context;

/**
 * Register rar builtin classes (PECL rar; #6237).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!RarExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerException($ctx);
        VmRar::registerClasses($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerException(Context $ctx): void
    {
        if (isset($ctx->classes['rarexception'])) {
            return;
        }

        $entry = new \PHPCompiler\VM\ClassEntry('RarException');
        if (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $ctx->classes['rarexception'] = $entry;
    }
}
