<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\VM\Context;

/**
 * Register redis builtin classes (PECL phpredis; #6098 / #28094).
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
        self::registerClusterException($ctx);
        require_once __DIR__.'/RedisDepthMethods.php';
        require_once __DIR__.'/VmRedisCluster.php';
        require_once __DIR__.'/VmRedisArray.php';
        VmRedis::registerClass($ctx);
        VmRedisCluster::registerClass($ctx);
        VmRedisArray::registerClass($ctx);
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

    /** RedisClusterException extends RuntimeException (phpredis redis_cluster.stub.php; #28094). */
    private static function registerClusterException(Context $ctx): void
    {
        if (isset($ctx->classes['redisclusterexception'])) {
            return;
        }

        $entry = new \PHPCompiler\VM\ClassEntry('RedisClusterException');
        if (isset($ctx->classes['runtimeexception'])) {
            $entry->parentLc = 'runtimeexception';
        } elseif (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $ctx->classes['redisclusterexception'] = $entry;
    }
}
