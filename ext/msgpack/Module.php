<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;

/**
 * msgpack extension module entry (php-src ext/msgpack/msgpack.c; issue #7032).
 *
 * MessagePack encode/decode tracked in #6551. Register under {@see standard} so
 * extension_loaded('msgpack') and function_exists('msgpack_*') stay false until
 * pack/unpack are implemented (#11986), matching ZipArchive/gd phantom pattern.
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
