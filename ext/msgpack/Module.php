<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * msgpack extension module entry (php-src / PECL msgpack/msgpack-php msgpack.c; #6551, #17994, #27872).
 *
 * Register under {@see standard}; advertise logical {@code msgpack} extension and
 * pack/unpack (+ serialize aliases, MessagePack OO, MESSAGEPACK_OPT_*) when
 * {@see MsgpackExtensionPolicy::advertisesExtension()} — withheld on reference profile
 * (Zend 8.2 has no ext/msgpack).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!MsgpackExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (MsgpackConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
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
            new msgpack_serialize(),
            new msgpack_unserialize(),
        ];
    }
}
