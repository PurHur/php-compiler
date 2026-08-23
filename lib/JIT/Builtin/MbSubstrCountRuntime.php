<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_substr_count() — compiles MbSubstrCountJitHelper (#4637 AOT leftover).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_substr_count)
 */
final class MbSubstrCountRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSubstrCountJitHelper.php';

    private const SUBSTR_COUNT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstrCountJitHelper::substrCountArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUBSTR_COUNT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function substrCountHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SUBSTR_COUNT_LOGICAL, '#4637');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_substr_count'
        );
    }
}
