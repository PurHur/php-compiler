<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\ModuleAbstract;

/**
 * igbinary extension module entry (php-src ext/igbinary/igbinary.c; issue #7033).
 *
 * Binary serialize/unserialize tracked in #6573. Register under {@see standard} so
 * extension_loaded('igbinary') and function_exists('igbinary_*') stay false until
 * serialize/unserialize are implemented (#11993), matching msgpack phantom pattern.
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    public function getFunctions(): array
    {
        return [];
    }
}
