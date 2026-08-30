<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_detect_encoding() — MbDetectEncodingJitHelper (#34358 / #3075 / #35856).
 *
 * All-string NestedJIT ABI ({@code string,string,string}) matches {@see MbScrubRuntime}
 * ({@code __string__*} params). An {@code int} third arg forced boxed {@code __value__}
 * params: LLVM call verify failed, and bridge-boxed strings made {@code strlen} silent-0
 * then hung in isset-length walks (#35856).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class MbDetectEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbDetectEncodingJitHelper.php';

    private const DETECT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectEncodingJitHelper::detectArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DETECT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function detectHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::DETECT_LOGICAL, 'mb_detect_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_detect_encoding'
        );
    }
}
