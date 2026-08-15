<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for http_get_last_response_headers() (#7236, #21172; alias retired #28412).
 *
 * JIT/AOT standalone has no HTTP wrapper state yet — idle returns null like Zend php-src (#8769).
 */
final class JitHttpLastResponseHeaders
{
    public static function invoke(Context $context): Value
    {
        return self::nullResult($context);
    }

    public static function clear(Context $context): void
    {
        // JIT/AOT standalone has no HTTP wrapper state yet; clear is a no-op (#7024).
    }

    private static function nullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }
}
