<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Standalone AOT LLVM for include_path builtins (#9245, #11538).
 *
 * Embed/MCJIT routes through {@see IncludePathJitHelper} via {@see IncludePathRuntime}.
 * php-src: ext/standard/basic_functions.c — php_get_include_path / php_set_include_path
 */
final class IncludePathStandaloneLlvm
{
    public static function implement(Context $context): void
    {
        IncludePathRuntime::implementInitNoop($context);
        self::implementGetBridge($context);
        self::implementSetBridge($context);
        self::implementRestoreBridge($context);
        self::implementResolveStub($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetBridge(Context $context): void
    {
        $abiName = '__compiler_get_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_get_standalone');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $dot = $context->constantFromString('.');
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->builder->pointerCast($dot, $context->getTypeFromString('char*'))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $fn->getParam(0),
            $str
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetBridge(Context $context): void
    {
        $abiName = '__compiler_set_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_set_standalone');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $dot = $context->constantFromString('.');
        $oldStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->builder->pointerCast($dot, $context->getTypeFromString('char*'))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $fn->getParam(1),
            $oldStr
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreBridge(Context $context): void
    {
        $abiName = '__compiler_restore_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_restore_standalone');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementResolveStub(Context $context): void
    {
        $abiName = '__compiler_stream_resolve_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_resolve_standalone');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($abiName, $fn);
    }
}
