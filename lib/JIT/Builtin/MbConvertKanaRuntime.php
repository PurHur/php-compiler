<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_kana() — MbConvertKanaJitHelper (#34294 / #13099 / #35193).
 *
 * Runtime encoding assert: {@see MbConvertKanaJitHelper::assertEncodingArgv} (#35193).
 * Conversion for foldable string/mode uses compile-time {@see \PHPCompiler\ext\mbstring\KanaConvert}
 * (NestedJIT of KanaConvert fails module verify / SIGSEGVs under thin AOT).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
 */
final class MbConvertKanaRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertKanaJitHelper.php';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertKanaJitHelper::assertEncodingArgv';

    private const SELECT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertKanaJitHelper::selectEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASSERT_ENCODING_LOGICAL,
        self::SELECT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function selectHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SELECT_LOGICAL, 'mb_convert_kana_select');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_convert_kana_encoding');
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
