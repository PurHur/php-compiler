<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for @ silence ABI without compiled ErrorSilenceJitHelper (#9197, #11540).
 *
 * Embed/MCJIT routes through {@see ErrorSilenceJitHelper} via {@see SilenceRuntime}.
 * php-src: Zend/zend_execute.c — ZEND_SILENCE
 */
final class SilenceStandaloneLlvm
{
    public static function implement(Context $context): void
    {
        SilenceRuntime::ensureValueWriters($context);
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valPtr = $context->getTypeFromString('__value__*');
        $savedBuilder = $context->builder;

        foreach (['__compiler_begin_silence', '__compiler_end_silence'] as $abiName) {
            $fn = self::abiFunction(
                $context,
                $abiName,
                $context->context->functionType($voidTy, false)
            );
            if ($fn->countBasicBlocks() > 0) {
                $context->registerFunction($abiName, $fn);
                continue;
            }
            $entry = $fn->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
            $context->registerFunction($abiName, $fn);
        }

        $isel = self::abiFunction(
            $context,
            '__compiler_phpc_error_level_enabled',
            $context->context->functionType($i32, false, $i32)
        );
        if (0 === $isel->countBasicBlocks()) {
            $entry = $isel->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($i32->constInt(1, false));
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__compiler_phpc_error_level_enabled', $isel);

        $ier = self::abiFunction(
            $context,
            '__compiler_error_reporting',
            $context->context->functionType($voidTy, false, $i32, $i64, $valPtr)
        );
        if (0 === $ier->countBasicBlocks()) {
            $entry = $ier->appendBasicBlock('entry');
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($entry);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $ier->getParam(2),
                $i64->constInt(0, false)
            );
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction('__compiler_error_reporting', $ier);

        $context->builder = $savedBuilder;
        $context->builder->clearInsertionPosition();
    }

    private static function abiFunction(Context $context, string $abiName, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null === $probe) {
            $context->module->addFunction($abiName, $ft);
            $probe = $context->module->getNamedFunction($abiName);
        }
        if (null === $probe) {
            throw new \LogicException($abiName.' missing after standalone ABI declare (#11540)');
        }

        return $probe;
    }
}
