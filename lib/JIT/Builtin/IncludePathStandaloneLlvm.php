<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

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
        $newLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $fn->getParam(0)
        );
        $emptyBlock = BasicBlockHelper::append($context, 'include_path_set_standalone_empty');
        $okBlock = BasicBlockHelper::append($context, 'include_path_set_standalone_ok');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $newLen, $i64->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBlock);
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
