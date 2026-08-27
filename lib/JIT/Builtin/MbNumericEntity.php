<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_encode/decode_numericentity() — MbNumericEntityJitHelper (#35210 leftover of #7237).
 *
 * NestedJIT via {@see JitNestedHelperCoerce::callHelper} for encode4/decode4 int map ABI
 * (#35254 leftover of #35210; peer {@see MbStrPad} / {@see MbStrSplit}). Assert stays a
 * two-string raw call (peer {@see MbTrimRuntime}).
 *
 * Runtime encoding assert: {@see MbNumericEntityJitHelper::assertEncodingArgv}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_numericentity)
 */
final class MbNumericEntity
{
    private const HELPER_PATH = '/ext/mbstring/MbNumericEntityJitHelper.php';

    private const ENCODE4 = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::encode4';

    private const DECODE4 = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::decode4';

    private const DECODE_SCAN = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::decodeDecScan';

    private const ASSERT_ENCODING = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE4,
        self::DECODE4,
        self::DECODE_SCAN,
        self::ASSERT_ENCODING,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function encode4Helper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ENCODE4, '#35210');
    }

    public static function decode4Helper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::DECODE4, '#35210');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING, '#35210');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Skip helper-runtime cache: stale prelinked unit mismatches NestedJIT int ABI
        // (Module verify / thin-AOT SIGSEGV; peer preg #26888). (#35254)
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_numericentity',
            true
        );
    }
}
