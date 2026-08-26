<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strpos() / mb_stripos() / mb_strrpos() / mb_strripos() / mb_strstr() / mb_stristr() /
 * mb_strrchr() / mb_strrichr() — compiles MbSearchJitHelper (#34146 / #34158 / #34166 / #34211 / #20006).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos), mb_stripos, mb_strrpos, mb_strripos, mb_strstr,
 * mb_stristr, mb_strrchr, mb_strrichr
 */
final class MbSearchRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSearchJitHelper.php';

    private const STRPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strposArgv';

    private const STRIPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::striposArgv';

    private const STRRPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strrposArgv';

    private const STRRIPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strriposArgv';

    private const STRSTR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strstrArgv';

    private const STRISTR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::stristrArgv';

    private const STRRCHR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strrchrArgv';

    private const STRRICHR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strrichrArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPOS_LOGICAL,
        self::STRIPOS_LOGICAL,
        self::STRRPOS_LOGICAL,
        self::STRRIPOS_LOGICAL,
        self::STRSTR_LOGICAL,
        self::STRISTR_LOGICAL,
        self::STRRCHR_LOGICAL,
        self::STRRICHR_LOGICAL,
        self::ASSERT_ENCODING_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function strposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRPOS_LOGICAL, '#34146');
    }

    public static function striposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRIPOS_LOGICAL, '#34158');
    }

    public static function strrposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRPOS_LOGICAL, '#34166');
    }

    public static function strriposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRIPOS_LOGICAL, 'mb_strripos');
    }

    public static function strstrHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRSTR_LOGICAL, '#34211');
    }

    public static function stristrHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRISTR_LOGICAL, 'mb_stristr');
    }

    public static function strrchrHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRCHR_LOGICAL, 'mb_strrchr');
    }

    public static function strrichrHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRICHR_LOGICAL, 'mb_strrichr');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_search_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_strrchr'
        );
    }
}
