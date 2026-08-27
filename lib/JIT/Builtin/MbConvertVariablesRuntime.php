<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_variables() — MbConvertVariablesJitHelper (#35315 / #4572).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertVariablesJitHelper.php';

    private const ENCODING_HELPER_PATH = '/ext/mbstring/MbConvertEncodingJitHelper.php';

    private const CONVERT_STRING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertVariablesJitHelper::convertStringArgv';

    private const DETECT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertVariablesJitHelper::detectFromArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_STRING_LOGICAL,
        self::DETECT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function convertStringHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_STRING_LOGICAL, 'mb_convert_variables');
    }

    public static function detectHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::DETECT_LOGICAL, 'mb_convert_variables');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::ENCODING_HELPER_PATH, self::HELPER_PATH],
            self::COMPILED_HELPERS,
            'mb_convert_variables'
        );
    }
}
