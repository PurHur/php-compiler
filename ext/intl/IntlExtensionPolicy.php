<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825).
 *
 * Grapheme helpers and intl_* functions require a loaded intl extension on Zend; partial
 * PHP implementations stay compiled in-tree but are withheld from function_exists() and
 * intl OOP class_exists() until {@see ModuleRegistry::extensionLoaded}('intl') is true
 * (full ext/intl parity, #11472).
 */
final class IntlExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return ModuleRegistry::extensionLoaded('intl');
    }
}
