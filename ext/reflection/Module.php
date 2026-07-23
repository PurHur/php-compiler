<?php

declare(strict_types=1);

namespace PHPCompiler\ext\reflection;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;

/**
 * reflection extension builtins (php-src ext/reflection; VM Reflection* classes are core).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!CompilerVersion::supportsIsAnonymousClass()) {
            return [];
        }

        return [
            new is_anonymous_class(),
        ];
    }
}
