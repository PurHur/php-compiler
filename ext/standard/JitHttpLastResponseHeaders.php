<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for http_get_last_response_headers() / get_last_response_headers() (#7236).
 *
 * JIT/AOT standalone returns null until stream HTTP wrapper state is wired in LLVM.
 */
final class JitHttpLastResponseHeaders
{
    public static function invoke(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    public static function clear(Context $context): void
    {
        // JIT/AOT standalone has no HTTP wrapper state yet; clear is a no-op (#7024).
    }
}
