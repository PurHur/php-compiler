<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StatCacheRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for clearstatcache() — clears JIT/AOT stat cache via StatCacheJitHelper (#9110, #9244). */
final class JitClearstatcache
{
    public static function invoke(Context $context, int $argc, JITVariable ...$args): Value
    {
        StatCacheRuntime::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');

        $hasFilename = 2 === $argc
            && isset($args[1])
            && !NamedOptionalCallArgs::isOmittedOptional($args[1]);
        $hasClearRealpath = isset($args[0]) && !NamedOptionalCallArgs::isOmittedOptional($args[0]);

        $clearRealpath = $i1->constInt(0, false);
        $filename = $strPtr->constNull();

        if ($hasClearRealpath) {
            // Compile-time null under strict: catchable TypeError then stop IR (#31245 / peer #30169).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'clearstatcache(): Argument #1 ($clear_realpath_cache) must be of type bool, null given'
                );
                JitNativeString::ensureInsertBlock($context);
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31245).
            $clearRealpath = JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[0],
                'clearstatcache',
                'clear_realpath_cache',
                1
            );
        }
        if ($hasFilename) {
            $filename = JitStringBuiltinArg::lower($context, $args[1], 'clearstatcache', 1, 'filename');
        }

        $context->builder->call(
            $context->lookupFunction(StatCacheRuntime::FN_CLEAR),
            $i32->constInt($argc, false),
            $clearRealpath,
            $filename
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }
}
