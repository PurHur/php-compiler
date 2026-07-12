<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;

/**
 * msgpack extension module entry (php-src ext/msgpack/msgpack.c; #6551, #17994).
 *
 * Register under {@see standard}; advertise logical {@code msgpack} extension and
 * msgpack_pack()/msgpack_unpack() when {@see MsgpackExtensionPolicy::advertisesExtension()}
 * — withheld on reference profile (Zend 8.2 has no ext/msgpack).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!MsgpackExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['msgpack'];
    }

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
