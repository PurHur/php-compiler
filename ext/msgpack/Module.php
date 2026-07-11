<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;

/**
 * msgpack extension module entry (php-src ext/msgpack/msgpack.c; #6551).
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
