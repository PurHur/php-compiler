<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strpos() / mb_stripos() / mb_strrpos() / mb_strripos() — compiles MbSearchJitHelper
 * (#34146 / #34158 / #34166 leftover of #27187).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos), mb_stripos, mb_strrpos, mb_strripos
 */
final class MbSearchRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSearchJitHelper.php';

    private const STRPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strposArgv';

    private const STRIPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::striposArgv';

    private const STRRPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strrposArgv';

    private const STRRIPOS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSearchJitHelper::strriposArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPOS_LOGICAL,
        self::STRIPOS_LOGICAL,
        self::STRRPOS_LOGICAL,
        self::STRRIPOS_LOGICAL,
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

    public static function striposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRIPOS_LOGICAL, '#34158');
    }

    public static function strrposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRPOS_LOGICAL, '#34166');
    }

    public static function strriposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRIPOS_LOGICAL, 'mb_strripos');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34166'
        );
    }
}
