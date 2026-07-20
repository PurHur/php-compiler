<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\CompilerVersion;

/** ext/mongodb surface advertisement — PECL mongodb (#6575). */
final class MongodbExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsMongodb();
    }
}
