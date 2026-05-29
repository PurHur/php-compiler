<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for json_validate() via __compiler_json_validate (issue #3101). */
final class JitJsonValidate
{
    public static function invoke(Context $context, JITVariable $json, JITVariable $depth): Value
    {
        $jsonPtr = JitStringArg::lower($context, $json, 'json_validate() argument #1');
        $depthVal = JitLongArg::lower($context, $depth, 'json_validate() argument #2');

        return self::invokeWithDepth($context, $jsonPtr, $depthVal);
    }

    public static function invokeWithDepth(Context $context, Value $jsonPtr, Value $depthVal): Value
    {
        $code = $context->builder->call(
            $context->lookupFunction('__compiler_json_validate'),
            $jsonPtr,
            $depthVal
        );

        return $context->builder->icmpEq(
            $code,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
    }
}
