<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * memcached extension module entry (PECL php-memcached; #6099).
 *
 * PHP-in-PHP Memcached class via ASCII protocol over TCP — no runtime/*.c growth.
 * Advertise logical {@code memcached} when {@see MemcachedExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    /** PECL memcached PHP_MEMCACHED_VERSION-style */
    private const MEMCACHED_VERSION = '3.2.0';

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return self::MEMCACHED_VERSION;
    }
}
