<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_trim() / mb_ltrim() / mb_rtrim() — MbTrimJitHelper (#34379).
 *
 * Encoding assert: {@see MbTrimEncodingJitHelper} in a separate NestedJIT unit (#35199).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_trim)
 */
final class MbTrimRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbTrimJitHelper.php';

    private const ENCODING_HELPER_PATH = '/ext/mbstring/MbTrimEncodingJitHelper.php';

    private const TRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::trimDefault';

    private const LTRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::ltrimDefault';

    private const RTRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::rtrimDefault';

    private const TRIM_CHARS = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::trimChars';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbTrimEncodingJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TRIM_DEFAULT,
        self::LTRIM_DEFAULT,
        self::RTRIM_DEFAULT,
        self::TRIM_CHARS,
    ];

    /** @var list<string> */
    private const COMPILED_ENCODING_HELPERS = [
        self::ASSERT_ENCODING_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureEncodingLinked(Context $context): void
    {
        self::ensureEncodingHelperCompiled($context);
    }

    public static function trimDefaultHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::TRIM_DEFAULT, 'mb_trim');
    }

    public static function ltrimDefaultHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::LTRIM_DEFAULT, 'mb_ltrim');
    }

    public static function rtrimDefaultHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::RTRIM_DEFAULT, 'mb_rtrim');
    }

    public static function trimCharsHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::TRIM_CHARS, 'mb_trim_chars');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureEncodingHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_trim_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_trim'
        );
    }

    private static function ensureEncodingHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ENCODING_HELPER_PATH,
            self::COMPILED_ENCODING_HELPERS,
            'mb_trim_encoding'
        );
    }
}
