<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/simdjson surface advertisement — PECL simdjson (#22530). Folded to ext.json advertise (#36204).
 */
final class SimdjsonExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('simdjson');
    }
}
