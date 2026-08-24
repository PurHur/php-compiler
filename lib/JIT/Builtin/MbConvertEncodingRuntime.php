<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_encoding() — MbConvertEncodingJitHelper (#34309 / #6251).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_encoding)
 */
final class MbConvertEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertEncodingJitHelper.php';

    private const CONVERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertEncodingJitHelper::convertArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function convertHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_LOGICAL, 'mb_convert_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_convert_encoding'
        );
    }
}
