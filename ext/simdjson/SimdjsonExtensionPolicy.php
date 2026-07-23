<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\CompilerVersion;

/**
 * ext/simdjson surface advertisement — PECL crazyxman/awesomized simdjson_php (#22530).
 *
 * Pure PHP {@see VmSimdjson} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsSimdjson()}.
 */
final class SimdjsonExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsSimdjson();
    }
}
