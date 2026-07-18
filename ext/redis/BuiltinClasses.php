<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\VM\Context;

/**
 * Register redis builtin classes (PECL phpredis; #6098).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!RedisExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerException($ctx);
        require_once __DIR__.'/RedisDepthMethods.php';
        VmRedis::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerException(Context $ctx): void
    {
        if (isset($ctx->classes['redisexception'])) {
            return;
        }

        $entry = new \PHPCompiler\VM\ClassEntry('RedisException');
        if (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $ctx->classes['redisexception'] = $entry;
    }
}
