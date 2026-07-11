<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for rand() — compiles RandJitHelper (#11908). */
final class Rand
{
    private const MT_RAND31 = 'PHPCompiler\\ext\\standard\\RandJitHelper::mtRand31';

    private const RAND_RANGE = 'PHPCompiler\\ext\\standard\\RandJitHelper::randRange';

    private const MT_RAND_RANGE = 'PHPCompiler\\ext\\standard\\RandJitHelper::mtRandRange';

    private const SEED = 'PHPCompiler\\ext\\standard\\RandJitHelper::seed';

    public static function ensureLinked(Context $context): void
    {
        self::ensureHelper($context, self::MT_RAND31);
        self::ensureHelper($context, self::RAND_RANGE);
        self::ensureHelper($context, self::MT_RAND_RANGE);
        self::ensureHelper($context, self::SEED);
    }

    public static function seed(Context $context): \PHPLLVM\Value\Function_
    {
        return self::helperFunction($context, self::SEED);
    }

    public static function mtRand31(Context $context): \PHPLLVM\Value\Function_
    {
        return self::helperFunction($context, self::MT_RAND31);
    }

    public static function randRange(Context $context): \PHPLLVM\Value\Function_
    {
        return self::helperFunction($context, self::RAND_RANGE);
    }

    public static function mtRandRange(Context $context): \PHPLLVM\Value\Function_
    {
        return self::helperFunction($context, self::MT_RAND_RANGE);
    }

    private static function helperFunction(Context $context, string $logical): \PHPLLVM\Value\Function_
    {
        self::ensureHelper($context, $logical);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after compile (#11908)');
        }

        return $fn;
    }

    private static function ensureHelper(Context $context, string $logical): void
    {
        $lc = \strtolower($logical);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).'/ext/standard/RandJitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'RandJitHelper.php');
        if (null === $block) {
            throw new \LogicException('RandJitHelper.php parseAndCompile failed (#11908)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc]) && !self::anyHelperPresent($context)) {
            throw new \LogicException('RandJitHelper was not compiled for JIT (#11908)');
        }
    }

    private static function anyHelperPresent(Context $context): bool
    {
        foreach ([self::MT_RAND31, self::RAND_RANGE, self::MT_RAND_RANGE] as $logical) {
            if (isset($context->functions[\strtolower($logical)])) {
                return true;
            }
        }

        return false;
    }
}
