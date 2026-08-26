<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_internal_encoding() — MbInternalEncodingJitHelper (#35221 leftover of #13100/#20014).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_internal_encoding)
 */
final class MbInternalEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbInternalEncodingJitHelper.php';

    private const GET_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbInternalEncodingJitHelper::getArgv';

    private const SET_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbInternalEncodingJitHelper::setArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_LOGICAL,
        self::SET_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function getHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::GET_LOGICAL, '#35221');
    }

    public static function setHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SET_LOGICAL, 'mb_internal_encoding_set');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_internal_encoding'
        );
    }
}
