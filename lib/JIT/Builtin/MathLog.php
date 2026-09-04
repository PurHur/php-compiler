<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for log() via {@code llvm.log.f64} (#36386).
 *
 * Peer of {@see MathExp} / {@see MathSin}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(log)}
 * → C {@code log}; the LLVM intrinsic matches IEEE libm behaviour.
 * The PHP series helper remains for NestedJIT-safe reference only.
 * Optional {@code $base} stays pure LLVM on {@code phpc_log} / {@code phpc_log10}.
 */
final class MathLog
{
    private const LLVM_LOG = 'llvm.log.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_LOG = 'phpc_log';

    private const BRIDGE_ENTRY = 'log_llvm_f64_entry';

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::llvmLogIntrinsic($context);
        self::ensurePhpcLogBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmLogIntrinsic($context), $num);
    }

    /**
     * log($num, $base) — php-src math.c order: base 2 / 10 / 1 / ≤0 / else.
     */
    public static function invokeWithBase(Context $context, Value $num, Value $base): Value
    {
        self::ensureLinked($context);
        MathLog10::ensureLinked($context);

        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $one = $double->constReal(1.0);
        $two = $double->constReal(2.0);
        $ten = $double->constReal(10.0);
        $ln2 = $double->constReal(\M_LN2);
        $nan = $double->constReal(\NAN);

        // php-src: after base==1 fast path, base ≤ 0 → ValueError. NAN base is not ≤ 0.
        $tooSmall = $context->builder->fcmp(Builder::REAL_OLE, $base, $zero);
        $okBase = $context->builder->not($tooSmall);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $okBase,
            'log_base_gt0_'.(++self::$seq),
            'log(): Argument #2 ($base) must be greater than 0'
        );

        $is2 = $context->builder->fcmp(Builder::REAL_OEQ, $base, $two);
        $is10 = $context->builder->fcmp(Builder::REAL_OEQ, $base, $ten);
        $is1 = $context->builder->fcmp(Builder::REAL_OEQ, $base, $one);

        $logNum = self::invoke($context, $num);
        $asLog2 = $context->builder->fdiv($logNum, $ln2);
        $asLog10 = MathLog10::invoke($context, $num);
        $logBase = self::invoke($context, $base);
        $general = $context->builder->fdiv($logNum, $logBase);

        // Prefer php-src fast-path order via nested select (2 → 10 → 1 → else).
        $afterOne = $context->builder->select($is1, $nan, $general);
        $afterTen = $context->builder->select($is10, $asLog10, $afterOne);

        return $context->builder->select($is2, $asLog2, $afterTen);
    }

    private static function llvmLogIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_LOG);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_LOG,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_log} → {@code llvm.log.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcLogBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_LOG);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_LOG, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_LOG,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmLogIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_LOG, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
