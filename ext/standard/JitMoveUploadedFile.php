<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for move_uploaded_file() via __compiler_move_uploaded_file (issue #2005). */
final class JitMoveUploadedFile
{
    /** @return Value true when __compiler_move_uploaded_file returns 1 */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
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
