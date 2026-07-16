<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringFsDir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for move_uploaded_file() via JitUploadTempKernel (issue #5346, #19723). */
final class JitMoveUploadedFile
{
    /** @return Value */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        StringFsDir::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_move_uploaded_file'),
            $fromStr,
            $toStr
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
