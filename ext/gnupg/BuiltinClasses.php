<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\VM\Context;

/**
 * Register gnupg class (PECL gnupg; #6668).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!GnupgExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmGnupgObject::registerClass($ctx);
    }
}
