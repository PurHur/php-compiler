<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_str_split() — compiles MbStrSplitJitHelper into the module (#26870 / #34278 / #34880).
 *
 * Helper returns a joined string (thin AOT cannot NestedJIT HashTable — peer explode #27660);
 * {@see \PHPCompiler\ext\mbstring\JitMbStrSplit} rebuilds the HT via JitExplode.
 * Forced user-script NestedJIT via HelperRuntimeCache USER_SCRIPT_INLINE_ONLY (#34278).
 * assertEncodingArgv is Argument #3 (#34880 leftover of #34278 / peer #34875).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class MbStrSplitRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbStrSplitJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrSplitJitHelper::strSplitArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrSplitJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
        self::ASSERT_ENCODING_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Lazy via ensureLinked from JitMbStrSplit (peer MbStrcut).
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26870');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_str_split_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26870',
            true
        );
    }
}
