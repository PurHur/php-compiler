<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\VM\Context;

/**
 * Register hash extension builtin classes (php-src ext/hash/hash.c; issue #7174).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmHashContext::registerClass($ctx);
    }
}
