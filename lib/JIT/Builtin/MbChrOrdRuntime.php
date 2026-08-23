<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_ord() — compiles MbChrOrdJitHelper (#34243 leftover of #33547).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_ord)
 */
final class MbChrOrdRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbChrOrdJitHelper.php';

    private const ORD_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbChrOrdJitHelper::ordArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ORD_LOGICAL,
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_ord'
        );
    }
}
