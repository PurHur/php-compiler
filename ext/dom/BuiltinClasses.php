<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;

/** Register dom extension builtin classes (php-src ext/dom/php_dom.c; issue #6140). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmDom::registerClasses($ctx);
    }
}
