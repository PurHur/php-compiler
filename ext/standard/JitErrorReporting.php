<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** error_reporting() JIT/AOT lowering — shared phpc_ini_set.c state (#3220). */
final class JitErrorReporting
{
    public static function invoke(Context $context, ?JITVariable $levelArg): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $hasLevel = $i32->constInt(0, false);
        $newLevel = $i64->constInt(0, false);
        if (null !== $levelArg && JITVariable::TYPE_NULL !== $levelArg->type) {
            $hasLevel = $i32->constInt(1, false);
            $newLevel = JitLongArg::lower($context, $levelArg, 'error_reporting() level');
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_error_reporting'),
            $hasLevel,
            $newLevel,
            $ptr
        );

        return $ptr;
    }
}
