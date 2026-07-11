<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zip extension module entry (php-src ext/zip/php_zip.c; issue #5869).
 *
 * ZipArchive parity tracked in #3337; register under {@see standard} so
 * extension_loaded('zip') stays false until libzip ships (#11676).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
