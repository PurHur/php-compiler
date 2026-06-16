<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM lowering for http_get_last_response_headers() / get_last_response_headers() (#7236).
 *
 * JIT/AOT standalone returns [] until stream HTTP wrapper state is wired in LLVM (#8769).
 */
final class JitHttpLastResponseHeaders
{
    public static function invoke(Context $context): Value
    {
        return ArrayBuiltinHelper::emptyArray($context);
    }

    public static function clear(Context $context): void
    {
        // JIT/AOT standalone has no HTTP wrapper state yet; clear is a no-op (#7024).
    }
}
