<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;

/**
 * opcache extension module entry (php-src ext/opcache/zend_accelerator_module.c; issue #4421).
 *
 * Userland probes only — no Zend accelerator engine. Returns disabled status arrays.
 * Module name is "Zend OPcache" (not bare "opcache") — php-src zend_module_entry (#24993).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'Zend OPcache';
    }

    public function getFunctions(): array
    {
        return [
            new opcache_get_status(),
            new opcache_get_configuration(),
            new opcache_reset(),
            new opcache_compile_file(),
            new opcache_invalidate(),
            new opcache_is_script_cached(),
            ...(CompilerVersion::supportsOpcacheIsScriptCachedInFileCache()
                ? [new opcache_is_script_cached_in_file_cache()]
                : []),
        ];
    }
}
