<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strwidth() / mb_strimwidth() / mb_str_pad() — compiles MbStrwidthJitHelper (#3495, #26617).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MbStrcut #26598 / StringZstd #26596).
 */
final class MbStrwidth
{
    private const HELPER_PATH = '/ext/mbstring/MbStrwidthJitHelper.php';

    private const HELPER_STRWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strwidth';
    private const HELPER_STRIMWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strimwidth';
    private const HELPER_STRPAD = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strPad';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_STRWIDTH,
        self::HELPER_STRIMWIDTH,
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

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_STRIMWIDTH, '#26617');
    }

    public static function strPadFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_STRPAD, '#26617');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26617'
        );
    }
}
