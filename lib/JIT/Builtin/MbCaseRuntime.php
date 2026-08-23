<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strtoupper() / mb_strtolower() — compiles MbCaseJitHelper (peer #3495 / MbStrwidth).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper), PHP_FUNCTION(mb_strtolower)
 */
final class MbCaseRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbCaseJitHelper.php';

    private const STRTOUPPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::strtoupperArgv';

    private const STRTOLOWER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::strtolowerArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRTOUPPER_LOGICAL,
        self::STRTOLOWER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function strtoupperHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRTOUPPER_LOGICAL, 'mb_strtoupper');
    }

    public static function strtolowerHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRTOLOWER_LOGICAL, 'mb_strtolower');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_case'
        );
    }
}
