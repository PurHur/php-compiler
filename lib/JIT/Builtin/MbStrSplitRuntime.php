<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_str_split() — compiles MbStrSplitJitHelper into the module (#26870 / #34278).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} with skipHelperRuntimeCache
 * (peer MbStrcut #34256 — prelinked unit.o SIGSEGVs on runtime int length).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class MbStrSplitRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbStrSplitJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrSplitJitHelper::strSplitRuntimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
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

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#34278');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34278',
            true
        );
    }
}
