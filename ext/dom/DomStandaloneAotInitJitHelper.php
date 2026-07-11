<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;

/**
 * Register ext/dom builtin classes for thin standalone user-script AOT (#17391).
 *
 * php-src: ext/dom/php_dom.c — dom_register_constants / class entries
 */
final class DomStandaloneAotInitJitHelper
{
    public static function registerDomExtensionClasses(Context $ctx): void
    {
        BuiltinClasses::register($ctx);
    }
}
