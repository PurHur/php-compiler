<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Stamp compile-time method metadata on ReflectionMethod at construct (#34216). */
final class ReflectionMethodQueryConstructHelper
{
    public const PROP_COMPILER_METHOD_FLAGS = '__compilerMethodFlags';

    public const PROP_COMPILER_METHOD_PARAM_COUNT = '__compilerMethodParamCount';

    public const PROP_COMPILER_METHOD_REQUIRED_PARAM_COUNT = '__compilerMethodRequiredParamCount';

    public const PROP_COMPILER_METHOD_HAS_RETURN_TYPE = '__compilerMethodHasReturnType';

    public static function emitStoreQueryMetadata(
        Context $context,
        Value $obj,
        Variable $classVar,
        Variable $methodVar
    ): void {
        $classLit = JitStringArg::compileTimeLiteral($classVar);
        $methodLit = JitStringArg::compileTimeLiteral($methodVar);
        if (null !== $classLit && null !== $methodLit) {
            $meta = ReflectionMethodQueryLowering::compileTimeMethodMetadata(
                $context,
                $classLit,
                $methodLit
            );
            if (null !== $meta) {
                ReflectionSetup::emitSetIntegerProperty(
                    $context,
                    $obj,
                    'ReflectionMethod',
                    self::PROP_COMPILER_METHOD_FLAGS,
                    $meta['flags']
                );
                ReflectionSetup::emitSetIntegerProperty(
                    $context,
                    $obj,
                    'ReflectionMethod',
                    self::PROP_COMPILER_METHOD_PARAM_COUNT,
                    $meta['total']
                );
                ReflectionSetup::emitSetIntegerProperty(
                    $context,
                    $obj,
                    'ReflectionMethod',
                    self::PROP_COMPILER_METHOD_REQUIRED_PARAM_COUNT,
                    $meta['required']
                );
                ReflectionSetup::emitSetIntegerProperty(
                    $context,
                    $obj,
                    'ReflectionMethod',
                    self::PROP_COMPILER_METHOD_HAS_RETURN_TYPE,
                    $meta['hasReturnType'] ? 1 : 0
                );

                return;
            }
        }

        $classVar = JitNativeString::coerce($context, $classVar);
        $methodVar = JitNativeString::coerce($context, $methodVar);
        $classStr = $context->helper->loadValue($classVar);
        $methodStr = $context->helper->loadValue($methodVar);
        $maps = ReflectionMethodQueryLowering::paramCountMapsForContext($context);
        $flags = ReflectionMethodQueryLookupRuntime::lookupFlagsInlineFromStrings(
            $context,
            $classStr,
            $methodStr,
            ReflectionMethodQueryLowering::visibilityMapForContext($context)
        );
        $total = ReflectionMethodQueryLookupRuntime::lookupParamCountInlineFromStrings(
            $context,
            $classStr,
            $methodStr,
            $maps['total']
        );
        $required = ReflectionMethodQueryLookupRuntime::lookupParamCountInlineFromStrings(
            $context,
            $classStr,
            $methodStr,
            $maps['required']
        );
        $hasReturn = ReflectionMethodQueryLookupRuntime::lookupParamCountInlineFromStrings(
            $context,
            $classStr,
            $methodStr,
            ReflectionMethodQueryLowering::hasReturnTypeMapForContext($context)
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_FLAGS,
            $flags
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_PARAM_COUNT,
            $total
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_REQUIRED_PARAM_COUNT,
            $required
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_HAS_RETURN_TYPE,
            $hasReturn
        );
    }

    public static function loadFlags(Context $context, Value $obj): Value
    {
        return ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_FLAGS
        );
    }

    public static function loadParamCount(Context $context, Value $obj): Value
    {
        return ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_PARAM_COUNT
        );
    }

    public static function loadRequiredParamCount(Context $context, Value $obj): Value
    {
        return ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_REQUIRED_PARAM_COUNT
        );
    }

    public static function loadHasReturnType(Context $context, Value $obj): Value
    {
        return ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionMethod',
            self::PROP_COMPILER_METHOD_HAS_RETURN_TYPE
        );
    }
}
