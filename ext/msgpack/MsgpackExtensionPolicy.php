<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\CompilerVersion;

/**
 * ext/msgpack surface advertisement — php-src ext/msgpack/msgpack.c PECL optional module (#17994).
 *
 * Pure PHP {@see VmMsgpack} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsMsgpack()}.
 */
final class MsgpackExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsMsgpack();
    }
}
