<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for modf() via ModfJitHelper PHP (#15200).
 *
 * Replaces libc `modf` LLVM lookup in ext/standard/modf.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(modf)
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ModfJitHelper compile (#15200)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ModfJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ModfJitHelper.php parseAndCompile failed (#15200)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#15200)');
            }
        }
    }
}
