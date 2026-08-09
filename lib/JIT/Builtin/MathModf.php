<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for internal modf via ModfJitHelper PHP (#15200, #22519, #29244).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Lcg #22495 / Frexp #29156).
 * Userland modf() is not registered (#25359); keep this for NestedJIT helpers.
 * NestedJIT-safe peel lives in {@see \PHPCompiler\ext\standard\ModfJitHelper} (no VmMath::modf).
 */
final class MathModf
{
    private const ABI_MODF = 'phpc_modf';

    private const HELPER_PATH = '/ext/standard/ModfJitHelper.php';

    private const COMPUTE_HELPER = 'PHPCompiler\\ext\\standard\\ModfJitHelper::compute';

    private const INT_PART_HELPER = 'PHPCompiler\\ext\\standard\\ModfJitHelper::intPart';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPUTE_HELPER,
        self::INT_PART_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $num, Value $outPtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MODF),
            $num,
            $outPtr
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_MODF);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_MODF, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementModfBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementModfBridge(Context $context): void
    {
        $abiName = self::ABI_MODF;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');
        $ft = $context->context->functionType($doubleTy, false, $doubleTy, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('modf_bridge_entry');
        $nullOut = $fn->appendBasicBlock('modf_bridge_null_out');
        $work = $fn->appendBasicBlock('modf_bridge_work');
        $done = $fn->appendBasicBlock('modf_bridge_done');

        $context->builder->positionAtEnd($entry);
        $num = $fn->getParam(0);
        $out = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $out, $out->typeOf()->constNull());
        $context->builder->branchIf($isNull, $nullOut, $work);

        $context->builder->positionAtEnd($nullOut);
        $fracOnly = $context->builder->call(self::helperFunction($context, self::COMPUTE_HELPER), $num);
        $context->builder->returnValue($fracOnly);

        $context->builder->positionAtEnd($work);
        $frac = $context->builder->call(self::helperFunction($context, self::COMPUTE_HELPER), $num);
        $intPart = $context->builder->call(self::helperFunction($context, self::INT_PART_HELPER));
        $intD = $intPart->typeOf() === $doubleTy
            ? $intPart
            : $context->builder->sitofp($intPart, $doubleTy);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $out,
            $intD
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($frac);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22519');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22519'
        );
    }
}
