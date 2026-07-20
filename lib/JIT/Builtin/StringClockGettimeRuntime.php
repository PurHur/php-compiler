<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_clock_gettime_assoc via ClockGettimeJitHelper (#11624, #21270).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit putenv). Peer: ProcessIdentity #21259 / gethostname #21166.
 * SSOT: {@see \PHPCompiler\ext\standard\VmHrtimeNative}, {@see \PHPCompiler\ext\standard\VmClockGettime}.
 * php-src: ext/standard/hrtime.c — clock_gettime
 */
final class StringClockGettimeRuntime
{
    private const HELPER_PATH = '/ext/standard/ClockGettimeJitHelper.php';

    private const ASSOC_HELPER = 'PHPCompiler\\ext\\standard\\ClockGettimeJitHelper::assoc';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASSOC_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementAssocBridge($context);
    }

    private static function implementAssocBridge(Context $context): void
    {
        $abiName = '__compiler_clock_gettime_assoc';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('clock_gettime_assoc_entry');
        $context->builder->positionAtEnd($entry);
        $clockId = $fn->getParam(0);
        $clockI64 = $clockId->typeOf() === $i64
            ? $clockId
            : $context->builder->zExt($clockId, $i64);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ASSOC_HELPER),
            [$clockI64]
        );
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#21270');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21270'
        );
    }
}
