<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strcut() — compiles MbStrcutJitHelper into the module (#4573, #26598).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringZstd #26596 / MetaTags #26568).
 */
final class MbStrcut
{
    private const HELPER_PATH = '/ext/mbstring/MbStrcutJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::strcutArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26598');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34256',
            true // skip stale helper-runtime cache (#34256)
        );
    }
}

/**
 * JIT/AOT link hook for mb_substr() — compiles MbSubstrJitHelper (#27028 / #34256).
 *
 * Helper lives in MbStrcutJitHelper.php (shared NestedJIT unit; no new inventory file).
 * Logical name substrArgv bypasses stale helper-runtime cache of 5-arg substr (#34256).
 */
final class MbSubstr
{
    private const HELPER_PATH = '/ext/mbstring/MbStrcutJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstrJitHelper::substrArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#34256');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34256',
            true // skip stale helper-runtime cache of co-located MbStrcut unit (#34256)
        );
    }
}
