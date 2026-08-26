<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_encoding_aliases() (#35216 leftover of #30795).
 *
 * Assert and aliases NestedJIT helpers are separate TUs — co-locating ValueError assert
 * with large alias-literal tables SEGVd under thin AOT.
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encoding_aliases)
 */
final class MbEncodingAliasesRuntime
{
    private const ALIASES_HELPER_PATH = '/ext/mbstring/MbEncodingAliasesJitHelper.php';

    private const ASSERT_HELPER_PATH = '/ext/mbstring/MbEncodingAliasesAssertJitHelper.php';

    private const ALIASES_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEncodingAliasesJitHelper::aliasesJoinedArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEncodingAliasesAssertJitHelper::assertEncodingArgv';

    public static function ensureLinked(Context $context): void
    {
        self::ensureAssertCompiled($context);
        self::ensureAliasesCompiled($context);
    }

    public static function aliasesHelper(Context $context): LlvmFunction
    {
        self::ensureAliasesCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ALIASES_LOGICAL, '#35216');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureAssertCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_encoding_aliases_encoding');
    }

    private static function ensureAliasesCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ALIASES_HELPER_PATH,
            [self::ALIASES_LOGICAL],
            'mb_encoding_aliases',
            true
        );
    }

    private static function ensureAssertCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ASSERT_HELPER_PATH,
            [self::ASSERT_ENCODING_LOGICAL],
            'mb_encoding_aliases_assert',
            true
        );
    }
}
