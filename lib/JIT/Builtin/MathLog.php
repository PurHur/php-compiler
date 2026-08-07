<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log() via LogJitHelper PHP (#15117, #21980, #27047, #28574).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathLog1p #28495 / MathExpm1 #28487 shape).
 * NestedJIT no longer needs a libc log(3) kernel — helper uses NestedJIT-safe series.
 * Optional `$base` is pure LLVM on phpc_log / phpc_log10.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(log)
 */
final class MathLog
{
    private const ABI_LOG = 'phpc_log';

    private const HELPER_PATH = '/ext/standard/LogJitHelper.php';

    private const LOG_HELPER = 'PHPCompiler\\ext\\standard\\LogJitHelper::logArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG_HELPER,
    ];

    private const BRIDGE_ENTRY = 'log_bridge_entry';

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_LOG),
            $num
        );
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

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_LOG);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_LOG, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::LOG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28574'
        );
    }
}
