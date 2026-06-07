<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;

/**
 * msgpack extension module entry (php-src ext/msgpack/msgpack.c; issue #7032).
 *
 * MessagePack encode/decode tracked in #6551; v0 skeleton enables function_exists() and inventory.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new msgpack_pack(),
            new msgpack_unpack(),
        ];
    }
}
