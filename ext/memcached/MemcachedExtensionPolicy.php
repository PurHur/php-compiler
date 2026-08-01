<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\CompilerVersion;

/**
 * ext/memcached surface advertisement — PECL php-memcached (#6099).
 *
 * Pure PHP {@see VmMemcached} ASCII client stays compiled in-tree but is withheld from
 * extension_loaded() / class_exists('Memcached') on the reference profile until
 * {@see CompilerVersion::supportsMemcached()} (Zend 8.2 harness typically has no pecl-memcached).
 */
final class MemcachedExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsMemcached();
    }
}
