<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\ModuleAbstract;

/**
 * igbinary extension module entry (php-src ext/igbinary/igbinary.c; issue #7033).
 *
 * Binary serialize/unserialize tracked in #6573; v0 skeleton enables function_exists() and inventory.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new igbinary_serialize(),
            new igbinary_unserialize(),
            new igbinary_pack(),
            new igbinary_unpack(),
        ];
    }
}
