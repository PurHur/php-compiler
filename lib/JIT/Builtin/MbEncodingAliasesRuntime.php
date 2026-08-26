<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_encoding_aliases() — MbEncodingAliasesJitHelper (#35216 leftover of #30795).
 *
 * Helper returns a joined string (thin AOT cannot NestedJIT HashTable — peer explode #27660);
 * {@see \PHPCompiler\ext\mbstring\JitMbEncodingRegistry} rebuilds the HT via JitExplode.
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encoding_aliases)
 */
final class MbEncodingAliasesRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbEncodingAliasesJitHelper.php';

    private const ALIASES_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEncodingAliasesJitHelper::aliasesJoinedArgv';

    private const ASSERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEncodingAliasesJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ALIASES_LOGICAL,
        self::ASSERT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function aliasesHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ALIASES_LOGICAL, '#35216');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_LOGICAL, 'mb_encoding_aliases_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_encoding_aliases'
        );
    }
}
