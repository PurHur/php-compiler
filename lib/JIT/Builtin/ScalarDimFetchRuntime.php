<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271, #10343).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchRuntime
{
    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

    private const HELPER_PATH = '/lib/VM/ScalarDimFetchJitHelper.php';

    private const EMIT_WARNING_HELPER = 'PHPCompiler\\VM\\ScalarDimFetchJitHelper::emitWarningForJitType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EMIT_WARNING_HELPER,
    ];

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

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementStandaloneEmitWarningBridge($context);
        } else {
            self::ensureJitHelperCompiled($context);
            self::implementEmbedEmitWarningBridge($context);
        }
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

    /** Standalone AOT: inline LLVM dispatch; message text from ScalarDimFetchJitHelper PHP SSOT (#10526). */
    private static function implementStandaloneEmitWarningBridge(Context $context): void
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
            self::emitStandaloneWarningForJitType($context, $jitType, $emptyFile);
            $context->builder->branch($done);
            $next = $fallBb;
        }

        $context->builder->positionAtEnd($next);
        self::emitStandaloneWarningForJitType($context, 255, $emptyFile);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function emitStandaloneWarningForJitType(Context $context, int $jitType, \PHPLLVM\Value $emptyFile): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $message = ScalarDimFetchJitHelper::warningMessageForJitType($jitType);
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function implementEmbedEmitWarningBridge(Context $context): void
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
        $i64 = $context->getTypeFromString('int64');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::EMIT_WARNING_HELPER),
            $context->builder->zext($fn->getParam(0), $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ScalarDimFetchJitHelper compile (#10343)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ScalarDimFetchJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ScalarDimFetchJitHelper.php parseAndCompile failed (#10343)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10343)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
