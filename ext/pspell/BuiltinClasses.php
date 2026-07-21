<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\VM\Context;

/**
 * Register PSpell\Dictionary (php-src ext/pspell; #6294).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!PspellExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmPspellDictionary::registerClass($ctx);
    }
}
