<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_chr() / mb_ord() — compiles MbChrOrdJitHelper (#34243 / #34250 / #34870).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_chr) / PHP_FUNCTION(mb_ord)
 */
final class MbChrOrdRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbChrOrdJitHelper.php';

    private const ORD_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbChrOrdJitHelper::ordArgv';

    private const CHR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbChrOrdJitHelper::chrArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbChrOrdJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ORD_LOGICAL,
        self::CHR_LOGICAL,
        self::ASSERT_ENCODING_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ordHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ORD_LOGICAL, '#34243');
    }

    public static function chrHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CHR_LOGICAL, '#34250');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_chr_ord_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_chr_ord'
        );
    }
}
