<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_trim() / mb_ltrim() / mb_rtrim() — MbTrimJitHelper (#34379).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_trim)
 */
final class MbTrimRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbTrimJitHelper.php';

    private const TRIM_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbTrimJitHelper::trimArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TRIM_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function trimHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::TRIM_LOGICAL, 'mb_trim');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_trim'
        );
    }
}
