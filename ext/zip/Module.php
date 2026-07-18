<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zip extension module entry (php-src ext/zip/php_zip.c; issues #5869, #3337, #6370, #18137).
 *
 * Register under {@see standard}; advertise logical {@code zip} extension, ZipArchive, and
 * zip_* procedural API when {@see ZipExtensionPolicy::advertisesExtension()} — withheld on
 * reference profile (Zend 8.2 harness has no ext/zip).
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
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!ZipExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['zip'];
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
            new zip_entry_compressedsize(),
            new zip_entry_compressionmethod(),
        ];
    }
}
