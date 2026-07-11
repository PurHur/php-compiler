<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;

/**
 * msgpack extension module entry (php-src ext/msgpack/msgpack.c; #6551, #17994).
 *
 * Register msgpack_pack()/msgpack_unpack() when
 * {@see MsgpackExtensionPolicy::advertisesExtension()} — withheld on reference profile.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!MsgpackExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new msgpack_pack(),
            new msgpack_unpack(),
        ];
    }
}
