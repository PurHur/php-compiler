<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\VM\Context;

/**
 * Register PSpell\Dictionary + PSpell\Config (php-src ext/pspell; #6294, #22229).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!PspellExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmPspellDictionary::registerClass($ctx);
        VmPspellConfig::registerClass($ctx);
    }
}
