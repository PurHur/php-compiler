<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_trim() / mb_ltrim() / mb_rtrim() — MbTrimJitHelper (#34379).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_trim)
 */
final class MbTrimRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbTrimJitHelper.php';

    private const TRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::trimDefault';

    private const LTRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::ltrimDefault';

    private const RTRIM_DEFAULT = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::rtrimDefault';

    private const TRIM_CHARS = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::trimChars';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TRIM_DEFAULT,
        self::LTRIM_DEFAULT,
        self::RTRIM_DEFAULT,
        self::TRIM_CHARS,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_trim'
        );
    }
}
