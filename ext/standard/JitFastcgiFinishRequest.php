<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fastcgi_finish_request() — CLI/AOT builds return false (#3466). */
final class JitFastcgiFinishRequest
{
    public static function invoke(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');

        // CLI/AOT builds are not FastCGI SAPI — php-src returns false (issue #3466).
        return $context->builder->icmp(
            Builder::INT_EQ,
            $i32->constInt(0, false),
            $i32->constInt(1, false)
        );
    }
}
