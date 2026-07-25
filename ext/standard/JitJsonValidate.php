<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for json_validate() via __compiler_json_validate (issue #3101). */
final class JitJsonValidate
{
    public static function invoke(Context $context, JITVariable $json, JITVariable $depth): Value
    {
        $jsonPtr = JitStringBuiltinArg::lower($context, $json, 'json_validate', 0, 'json');
        $depthVal = JitLongArg::lower($context, $depth, 'json_validate() argument #2');

        return self::invokeWithDepth($context, $jsonPtr, $depthVal);
    }

    public static function invokeWithDepth(Context $context, Value $jsonPtr, Value $depthVal): Value
    {
        StringJsonDecode::ensureLinked($context);
        $code = $context->builder->call(
            $context->lookupFunction('__compiler_json_validate'),
            $jsonPtr,
            $depthVal
        );

        // RESULT_VALID === 1 (VmJsonScanner); depth/syntax → false + last_error set by helper (#23007).
        return $context->builder->icmp(
            Builder::INT_EQ,
            $code,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
    }
}
