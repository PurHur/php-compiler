<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * rar extension module entry (PECL rar; #6237).
 *
 * PHP-in-PHP RarArchive/RarEntry via {@see RarEngine} store-method parser — no runtime/*.c.
 * Advertise logical {@code rar} when {@see RarExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    private const RAR_VERSION = '4.2.0';

    public function init(Runtime $runtime): void
    {
        if (RarExtensionPolicy::advertisesExtension()) {
            require_once __DIR__.'/bootstrap_rarexception.php';
        }
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return self::RAR_VERSION;
    }
}
