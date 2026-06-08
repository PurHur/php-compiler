<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\VM\Context;

/**
 * Register libxml extension builtin classes (php-src ext/libxml/libxml_error.c; issue #6058).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmLibxml::registerClass($ctx);
    }
}
