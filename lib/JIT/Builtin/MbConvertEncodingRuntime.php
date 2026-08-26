<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_encoding() — MbConvertEncodingJitHelper (#34309 / #6251).
 *
 * Runtime encoding assert: {@see MbConvertEncodingJitHelper::assertToEncodingArgv} /
 * {@see MbConvertEncodingJitHelper::assertFromEncodingArgv} (#35165 leftover of #34309).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_encoding)
 */
final class MbConvertEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertEncodingJitHelper.php';

    private const CONVERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertEncodingJitHelper::convertArgv';

    private const ASSERT_TO_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertEncodingJitHelper::assertToEncodingArgv';

    private const ASSERT_FROM_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertEncodingJitHelper::assertFromEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_LOGICAL,
        self::ASSERT_TO_LOGICAL,
        self::ASSERT_FROM_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function convertHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_LOGICAL, 'mb_convert_encoding');
    }

    public static function assertToEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_TO_LOGICAL, 'mb_convert_encoding_to');
    }

    public static function assertFromEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_FROM_LOGICAL, 'mb_convert_encoding_from');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_convert_encoding'
        );
    }
}
