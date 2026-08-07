<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * redis extension module entry (PECL phpredis / redis.c; #6098).
 *
 * PHP-in-PHP Redis class connect/get/set via RESP — no runtime/*.c growth. Advertise logical
 * {@code redis} when {@see RedisExtensionPolicy::advertisesExtension()} — withheld on reference
 * profile (Zend 8.2 typically has no phpredis).
 */
class Module extends ModuleAbstract
{
    /** PECL redis PHP_REDIS_VERSION-style */
    private const REDIS_VERSION = '6.0.2';

    public function init(Runtime $runtime): void
    {
        // Host-side exception classes must exist for VM catch bridges even when redis is
        // withheld from advertisement (instanceof checks in lib/VM.php; #6098 / #28094).
        require_once __DIR__.'/bootstrap_redisexception.php';
        require_once __DIR__.'/bootstrap_redisclusterexception.php';
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return self::REDIS_VERSION;
    }
}
