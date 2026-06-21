<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271, #10343).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchRuntime
{
    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::implementEmitWarningBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitWarning(Context $context, int $jitType): void
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EMIT_WARNING);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->call(
            $fn,
            $i8->constInt($jitType, false)
        );
    }

    private static function implementEmitWarningBridge(Context $context): void
    {
        $abiName = self::ABI_EMIT_WARNING;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('scalar_dim_fetch_warn_bridge_entry');
        $context->builder->positionAtEnd($entry);
        self::emitWarningForJitTypeParam($context, $fn->getParam(0));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function emitWarningForJitTypeParam(Context $context, \PHPLLVM\Value $jitTypeParam): void
    {
        $i8 = $context->getTypeFromString('int8');
        $fn = $context->builder->getInsertBlock()->getParent();
        if (!$fn instanceof LlvmFunction) {
            throw new \LogicException('scalar dim fetch bridge missing parent function (#10343)');
        }

        $done = $fn->appendBasicBlock('scalar_dim_fetch_warn_done');
        foreach ([
            JitVariable::TYPE_NULL,
            JitVariable::TYPE_NATIVE_BOOL,
            JitVariable::TYPE_NATIVE_LONG,
            JitVariable::TYPE_NATIVE_DOUBLE,
        ] as $jitType) {
            $matchBlock = $fn->appendBasicBlock('scalar_dim_fetch_warn_t'.$jitType);
            $nextCheck = $fn->appendBasicBlock('scalar_dim_fetch_warn_after_t'.$jitType);
            $context->builder->branchIf(
                $context->builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $jitTypeParam,
                    $i8->constInt($jitType, false)
                ),
                $matchBlock,
                $nextCheck
            );
            $context->builder->positionAtEnd($matchBlock);
            self::emitTriggerErrorMessage(
                $context,
                ScalarDimFetchJitHelper::warningMessageForJitType($jitType)
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextCheck);
        }

        self::emitTriggerErrorMessage(
            $context,
            ScalarDimFetchJitHelper::warningMessageForJitType(0)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitTriggerErrorMessage(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
