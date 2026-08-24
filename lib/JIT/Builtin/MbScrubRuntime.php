<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_scrub() — MbScrubJitHelper (#34338 / #6050).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_scrub)
 */
final class MbScrubRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbScrubJitHelper.php';

    private const SCRUB_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbScrubJitHelper::scrubArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SCRUB_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function scrubHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SCRUB_LOGICAL, 'mb_scrub');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_scrub'
        );
    }
}
