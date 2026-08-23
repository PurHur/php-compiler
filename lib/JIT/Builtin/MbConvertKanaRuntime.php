<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_kana() — MbConvertKanaJitHelper (#34294).
 *
 * Single-file NestedJIT (peer MbConvertCaseRuntime #34284) — no HELPER_BUNDLE;
 * the helper is self-contained (packed tables, streaming UTF-8).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
 */
final class MbConvertKanaRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertKanaJitHelper.php';

    private const CONVERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertKanaJitHelper::convertArgv';

    private const CONVERT_DEFAULT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertKanaJitHelper::convertDefaultArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_LOGICAL,
        self::CONVERT_DEFAULT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function convertHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_LOGICAL, 'mb_convert_kana');
    }

    public static function convertDefaultHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_DEFAULT_LOGICAL, 'mb_convert_kana_default');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_convert_kana'
        );
    }
}
