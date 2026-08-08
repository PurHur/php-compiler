<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for frexp() via FrexpJitHelper PHP (#15201, #22575, #29156).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * NestedJIT-safe peel lives in {@see \PHPCompiler\ext\standard\FrexpJitHelper}
 * (nextafter #28716 shape — no VmMath floor/log/pow-of-two under helper compile).
 * Replaces libc `frexp` LLVM lookup in ext/standard/frexp.php.
 * php-src: frexp(3) / ext/standard/math.c (userland frexp is a php-src phantom — #24133)
 */
final class MathFrexp
{
    private const ABI_FREXP = 'phpc_frexp';

    private const HELPER_PATH = '/ext/standard/FrexpJitHelper.php';

    private const COMPUTE_HELPER = 'PHPCompiler\\ext\\standard\\FrexpJitHelper::compute';

    private const EXPONENT_HELPER = 'PHPCompiler\\ext\\standard\\FrexpJitHelper::exponent';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPUTE_HELPER,
        self::EXPONENT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $num, Value $outPtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FREXP),
            $num,
            $outPtr
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FREXP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_FREXP, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFrexpBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFrexpBridge(Context $context): void
    {
        $abiName = self::ABI_FREXP;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($doubleTy, false, $doubleTy, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('frexp_bridge_entry');
        $nullOut = $fn->appendBasicBlock('frexp_bridge_null_out');
        $work = $fn->appendBasicBlock('frexp_bridge_work');
        $done = $fn->appendBasicBlock('frexp_bridge_done');

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
        $expVal = $context->builder->call(self::helperFunction($context, self::EXPONENT_HELPER));
        $expI64 = $expVal->typeOf() === $i64
            ? $expVal
            : $context->builder->sext($expVal, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $expI64
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($frac);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22575');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22575'
        );
    }
}
