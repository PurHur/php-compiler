<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strwidth() / mb_strimwidth() / mb_str_pad() — MbStrwidthJitHelper (#3495 / #26617 / #34264).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MbStrcut #34256).
 * strimwidthArgv skips helper-runtime cache so NestedJIT gets the coerced ABI (#34264).
 */
final class MbStrwidth
{
    /** @var list<string> */
    private const HELPER_PATHS = [
        '/ext/mbstring/EastAsianWidthTable.php',
        '/ext/mbstring/MbStrwidthJitHelper.php',
    ];

    private const HELPER_STRWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strwidth';
    private const HELPER_STRIMWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strimwidthArgv';
    private const HELPER_DISPLAY_WIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::displayWidthArgv';
    private const HELPER_TRIM_UTF8 = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::trimUtf8ToWidthArgv';
    private const HELPER_STRPAD = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strPad';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_STRWIDTH,
        self::HELPER_STRIMWIDTH,
        self::HELPER_DISPLAY_WIDTH,
        self::HELPER_TRIM_UTF8,
        self::HELPER_STRPAD,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
    }

    public static function strwidthFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_STRWIDTH, '#26617');
    }

    public static function strimwidthFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_STRIMWIDTH, '#34264');
    }

    public static function strPadFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_STRPAD, '#26617');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Bundle EastAsianWidthTable with the helper so NestedJIT resolves characterWidth (#34264).
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_PATHS,
            self::COMPILED_HELPERS,
            '#34264',
            true
        );
    }
}
