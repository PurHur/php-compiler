<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\Context;

/**
 * Register EnchantBroker / EnchantDictionary (php-src ext/enchant; #6230).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!EnchantExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmEnchantBroker::registerClass($ctx);
        VmEnchantDictionary::registerClass($ctx);
    }
}
