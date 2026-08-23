<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strtoupper/tolower/ucfirst/lcfirst — MbCaseJitHelper (#3495 / #34259).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper|mb_strtolower|mb_ucfirst|mb_lcfirst)
 */
final class MbCaseRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbCaseJitHelper.php';

    private const STRTOUPPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::strtoupperArgv';

    private const STRTOLOWER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::strtolowerArgv';

    private const UCFIRST_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::ucfirstArgv';

    private const LCFIRST_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbCaseJitHelper::lcfirstArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRTOUPPER_LOGICAL,
        self::STRTOLOWER_LOGICAL,
        self::UCFIRST_LOGICAL,
        self::LCFIRST_LOGICAL,
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

    public static function ucfirstHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::UCFIRST_LOGICAL, 'mb_ucfirst');
    }

    public static function lcfirstHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::LCFIRST_LOGICAL, 'mb_lcfirst');
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
