<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271).
 *
 * SSOT: {@see \PHPCompiler\VM\ErrorReporter}, {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}
 */
final class ScalarDimFetchRuntime
{
    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

    /** @var list<int> */
    private const WARN_JIT_TYPES = [
        JitVariable::TYPE_NULL,
        JitVariable::TYPE_NATIVE_BOOL,
        JitVariable::TYPE_NATIVE_LONG,
        JitVariable::TYPE_NATIVE_DOUBLE,
    ];

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
        StringTriggerError::ensureLinked($context);
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

        StringTriggerError::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('scalar_dim_fetch_warn_entry');
        $context->builder->positionAtEnd($entry);
        $typeByte = $fn->getParam(0);
        $done = BasicBlockHelper::append($context, 'scalar_dim_fetch_warn_done');
        $next = $entry;
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $warnTypes = self::WARN_JIT_TYPES;
        $lastIdx = \count($warnTypes) - 1;
        foreach ($warnTypes as $idx => $jitType) {
            $caseBb = BasicBlockHelper::append($context, 'scalar_dim_fetch_warn_t'.$jitType);
            $fallBb = $idx === $lastIdx
                ? BasicBlockHelper::append($context, 'scalar_dim_fetch_warn_default')
                : BasicBlockHelper::append($context, 'scalar_dim_fetch_warn_next_'.$jitType);
            $context->builder->positionAtEnd($next);
            $isType = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt($jitType, false)
            );
            $context->builder->branchIf($isType, $caseBb, $fallBb);

            $context->builder->positionAtEnd($caseBb);
            $message = ScalarDimFetchJitHelper::warningMessageForJitType($jitType);
            $msgGlobal = $context->constantFromString($message);
            $msgPtr = $context->builder->pointerCast($msgGlobal, $i8p);
            $context->builder->call(
                $context->lookupFunction('__compiler_trigger_error'),
                $msgPtr,
                $sizeT->constInt(\strlen($message), false),
                $i32->constInt(ErrorReporter::E_WARNING, false),
                $emptyFile,
                $i32->constInt(0, false)
            );
            $context->builder->branch($done);
            $next = $fallBb;
        }

        $context->builder->positionAtEnd($next);
        $message = ScalarDimFetchJitHelper::warningMessageForJitType(255);
        $msgGlobal = $context->constantFromString($message);
        $msgPtr = $context->builder->pointerCast($msgGlobal, $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
