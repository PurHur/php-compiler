<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_split() — compiles MbSplitJitHelper (#34391 leftover of #13367).
 *
 * Helper returns a joined string; {@see \PHPCompiler\ext\mbstring\JitMbEreg::invokeSplit}
 * rebuilds the HT via JitExplode (peer mb_str_split / #34278).
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class MbSplitRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSplitJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSplitJitHelper::splitArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#34391');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34391'
        );
    }
}
