<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hrtime_ns / __compiler_hrtime_pair via HrtimeJitHelper (#9182, #21378).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit putenv). Peer: StringClockGettimeRuntime #21270.
 * SSOT: {@see \PHPCompiler\ext\standard\VmHrtimeNative}.
 * php-src: ext/standard/hrtime.c — hrtime
 *
 * Pair form: NestedJIT array returns are empty under thin AOT (#26910). Build [sec, nsec] in the
 * LLVM bridge from nsInt (same monotonic read) via __hashtable__alloc / setLongAt.
 */
final class StringHrtimeRuntime
{
    private const HELPER_PATH = '/ext/standard/HrtimeJitHelper.php';

    private const NS_FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsFloat';

    private const NS_INT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NS_FLOAT_HELPER,
        self::NS_INT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementNsBridge($context);
        self::implementPairBridge($context);
    }

    private static function implementPairBridge(Context $context): void
    {
        $abiName = '__compiler_hrtime_pair';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction($abiName);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('hrtime_pair_entry');
        $context->builder->positionAtEnd($entry);

        // One NestedJIT scalar read (AOT-reliable); unpack to php-src [sec, nsec] (#26910).
        $totalRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::NS_INT_HELPER),
            []
        );
        $total = JitNestedHelperCoerce::coerceHelperScalarResult($context, $totalRaw, $i64);
        $nsPerSec = $i64->constInt(1_000_000_000, true);
        $sec = $context->builder->signedDiv($total, $nsPerSec);
        $nsec = $context->builder->signedRem($total, $nsPerSec);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $sizeT->constInt(0, false),
            $sec
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $sizeT->constInt(1, false),
            $nsec
        );
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNsBridge(Context $context): void
    {
        $abiName = '__compiler_hrtime_ns';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $doubleTy = $context->getTypeFromString('double');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($doubleTy, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_FLOAT_HELPER));
        } else {
            $i64 = $context->getTypeFromString('int64');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($i64, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_INT_HELPER));
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#21378');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21378'
        );
    }
}
