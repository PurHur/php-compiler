<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for lcg_value() — compiles LcgJitHelper (#3295, #22495).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamSocketPairRuntime #22468).
 * SSOT: {@see \PHPCompiler\ext\standard\LcgJitHelper}
 * php-src: ext/random/random.c — php_combined_lcg / lcg_value
 */
final class Lcg
{
    private const HELPER_PATH = '/ext/standard/LcgJitHelper.php';

    private const VALUE = 'PHPCompiler\\ext\\standard\\LcgJitHelper::value';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function value(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::VALUE);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22495');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22495'
        );
    }
}
