<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zip extension module entry (php-src ext/zip/php_zip.c; issues #5869, #3337, #6370).
 *
 * ZipArchive uses pure-PHP store engine ({@see ZipEngine}); procedural zip_* API in {@see VmZipProcedural}.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return 'zip';
    }

    public function getFunctions(): array
    {
        if (!ZipExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new zip_open(),
            new zip_close(),
            new zip_read(),
            new zip_entry_open(),
            new zip_entry_close(),
            new zip_entry_read(),
            new zip_entry_name(),
            new zip_entry_filesize(),
        ];
    }
}
