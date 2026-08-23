<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_case() TITLE modes — MbConvertCaseJitHelper (#34284).
 *
 * Own helper TU (not MbCaseRuntime) so committed mb_case cache units stay valid
 * while TITLE NestedJIT compiles separately.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
 */
final class MbConvertCaseRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertCaseJitHelper.php';

    private const TITLE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertCaseJitHelper::titleArgv';

    private const TITLE_SIMPLE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertCaseJitHelper::titleSimpleArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TITLE_LOGICAL,
        self::TITLE_SIMPLE_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function titleHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::TITLE_LOGICAL, 'mb_convert_case');
    }

    public static function titleSimpleHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::TITLE_SIMPLE_LOGICAL, 'mb_convert_case');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_convert_case_title'
        );
    }
}
