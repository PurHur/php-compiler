<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zip extension module entry (php-src ext/zip/php_zip.c; issue #5869).
 *
 * ZipArchive open/extract in ext/zip PHP (issues #3337, #6414); v1 skeleton enables class_exists() and inventory.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
