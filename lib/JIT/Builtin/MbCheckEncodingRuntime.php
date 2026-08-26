<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_check_encoding() — MbCheckEncodingJitHelper (#35211 leftover of #4571).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_check_encoding)
 */
final class MbCheckEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbCheckEncodingJitHelper.php';

    private const CHECK_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCheckEncodingJitHelper::checkArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHECK_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function checkHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CHECK_LOGICAL, '#35211');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_check_encoding'
        );
    }
}
