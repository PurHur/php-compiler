<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strlen() — MbStrlenJitHelper (#34625 leftover of #4405).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strlen)
 */
final class MbStrlenRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbStrlenJitHelper.php';

    private const STRLEN_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrlenJitHelper::strlenArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRLEN_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function strlenHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRLEN_LOGICAL, '#34625');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_strlen'
        );
    }
}
