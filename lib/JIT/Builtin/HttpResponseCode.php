<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * HTTP response status for JIT/AOT — thin facade over HttpResponseRuntime PHP (#9344).
 *
 * php-src: ext/standard/head.c — php_http_response_code
 */
final class HttpResponseCode
{
    public const APPLY_GET = 0;

    public const APPLY_SET_LONG = 1;

    public const APPLY_BOXED = 2;

    public static function implement(Context $context): void
    {
        HttpResponseRuntime::implement($context);
    }

    public static function emitResetForStandaloneMain(Context $context): void
    {
        HttpResponseRuntime::emitResetForStandaloneMain($context);
    }

    public static function emitStandaloneStatusLine(Context $context, Value $code64): void
    {
        HttpResponseRuntime::emitStandaloneStatusLine($context, $code64);
    }
}
