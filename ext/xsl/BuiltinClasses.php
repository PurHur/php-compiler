<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\VM\Context;

/** Register XSLTProcessor builtin class (php-src ext/xsl/php_xsl.c; #3665). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmXsl::registerClass($ctx);
    }
}
