<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_get_info() — MbGetInfoJitHelper (#20014 runtime type).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_get_info)
 */
final class MbGetInfoRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbGetInfoJitHelper.php';

    private const KIND_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbGetInfoJitHelper::kindArgv';

    private const PAYLOAD_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbGetInfoJitHelper::payloadArgv';

    private const INT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbGetInfoJitHelper::intArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KIND_LOGICAL,
        self::PAYLOAD_LOGICAL,
        self::INT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function kindHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::KIND_LOGICAL, '#20014');
    }

    public static function payloadHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PAYLOAD_LOGICAL, '#20014');
    }

    public static function intHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::INT_LOGICAL, '#20014');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_get_info',
            true
        );
    }
}
