<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for rand() — compiles RandJitHelper (#11908, #25252).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Lcg #22495).
 * SSOT: {@see \PHPCompiler\ext\standard\RandJitHelper}
 * php-src: ext/random/engine_mt19937.c — php_mt_rand / mt_rand / rand
 */
final class Rand
{
    private const HELPER_PATH = '/ext/standard/RandJitHelper.php';

    private const MT_RAND31 = 'PHPCompiler\\ext\\standard\\RandJitHelper::mtRand31';

    private const RAND_RANGE = 'PHPCompiler\\ext\\standard\\RandJitHelper::randRange';

    private const MT_RAND_RANGE = 'PHPCompiler\\ext\\standard\\RandJitHelper::mtRandRange';

    private const SEED = 'PHPCompiler\\ext\\standard\\RandJitHelper::seed';

    private const SEED_WITH_MODE = 'PHPCompiler\\ext\\standard\\RandJitHelper::seedWithMode';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MT_RAND31,
        self::RAND_RANGE,
        self::MT_RAND_RANGE,
        self::SEED,
        self::SEED_WITH_MODE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function seed(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::SEED);
    }

    public static function seedWithMode(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::SEED_WITH_MODE);
    }

    public static function mtRand31(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::MT_RAND31);
    }

    public static function randRange(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::RAND_RANGE);
    }

    public static function mtRandRange(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::MT_RAND_RANGE);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25252');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25252'
        );
    }
}
