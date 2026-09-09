<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/msgpack surface advertisement — PECL optional (#17994). Folded to ext.json advertise (#36204).
 */
final class MsgpackExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('msgpack');
    }
}
