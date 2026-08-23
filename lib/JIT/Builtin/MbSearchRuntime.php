<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strpos() — compiles MbSearchJitHelper (#34146 leftover of #27187).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos)
 */
final class MbSearchRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSearchJitHelper.php';

    private const STRPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strposArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPOS_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function strposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRPOS_LOGICAL, '#34146');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34146'
        );
    }
}
