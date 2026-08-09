<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * apcu extension module entry (PECL apcu / php-src ext/apcu; #6574, #24909, #27877).
 *
 * PHP-in-PHP in-process user cache — no runtime/*.c growth. Advertise apcu_* /
 * extension_loaded('apcu') only when {@see ApcuExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    /** PECL APCU_VERSION-style */
    private const APCU_VERSION = '5.1.24';

    public function getExtensionVersion(): string
    {
        return self::APCU_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!ApcuExtensionPolicy::advertisesExtension()) {
            return;
        }
        require_once __DIR__.'/ApcuConstants.php';
        require_once __DIR__.'/VmApcuIterator.php';
        foreach (ApcuConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        VmApcuIterator::registerClass($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!ApcuExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new apcu_store(),
            new apcu_fetch(),
            new apcu_add(),
            new apcu_inc(),
            new apcu_dec(),
            new apcu_cas(),
            new apcu_entry(),
            new apcu_delete(),
            new apcu_clear_cache(),
            new apcu_exists(),
            new apcu_cache_info(),
            new apcu_sma_info(),
            new apcu_key_info(),
            new apcu_enabled(),
        ];
    }
}
