<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\ModuleAbstract;

/**
 * opcache extension module entry (php-src ext/opcache/php_opcache.c; issue #4421).
 *
 * Userland probes only — no Zend accelerator engine. Returns disabled status arrays.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new opcache_get_status(),
            new opcache_get_configuration(),
            new opcache_reset(),
            new opcache_compile_file(),
            new opcache_invalidate(),
            new opcache_is_script_cached(),
        ];
    }
}
