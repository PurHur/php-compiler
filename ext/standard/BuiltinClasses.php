<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Context;

/**
 * Register ext/standard builtin classes (php-src ext/standard/streams.c / user_filters.c).
 *
 * StreamBucket is PHP 8.4+ only (#26923); ≤8.3 keeps stdClass from stream_bucket_new (#10325).
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
        VmStreamBucket::registerClass($ctx);
        VmZlibContext::registerClasses($ctx);
    }
}
