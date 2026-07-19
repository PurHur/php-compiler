<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Context;

/**
 * Register ext/standard builtin classes (php-src ext/standard/streams.c; #7086, #10325).
 *
 * stream_bucket_new() returns stdClass in php-src — no StreamBucket user class.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        DirectoryBuiltin::registerClass($ctx);
        PhpUserFilterBuiltin::registerClass($ctx);
        if (CompilerVersion::supportsRange()) {
            RangeBuiltin::registerClass($ctx);
        }
        StreamErrorBuiltin::registerClass($ctx);
        VmZlibContext::registerClasses($ctx);
    }
}
