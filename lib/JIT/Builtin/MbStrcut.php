<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strcut() / mb_substr() NestedJIT helpers (#4573 / #27028 / #34256 / #34875).
 *
 * Both *Argv symbols are listed in COMPILED_HELPERS (peer MbSearchRuntime) so NestedJIT
 * emits private utf8Step helpers for either entrypoint. assertEncodingArgv is Argument #4 (#34875).
 */
final class MbStrcut
{
    private const HELPER_PATH = '/ext/mbstring/MbStrcutJitHelper.php';

    private const STRCUT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::strcutArgv';

    private const SUBSTR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstrJitHelper::substrArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCUT_LOGICAL,
        self::SUBSTR_LOGICAL,
        self::ASSERT_ENCODING_LOGICAL,
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

        return JitVmHelperLink::lookupCompiled($context, self::STRCUT_LOGICAL, '#34256');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_strcut_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34256',
            true
        );
    }
}

final class MbSubstr
{
    private const HELPER_PATH = '/ext/mbstring/MbStrcutJitHelper.php';

    private const STRCUT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::strcutArgv';

    private const SUBSTR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstrJitHelper::substrArgv';

    private const ASSERT_ENCODING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::assertEncodingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCUT_LOGICAL,
        self::SUBSTR_LOGICAL,
        self::ASSERT_ENCODING_LOGICAL,
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

        return JitVmHelperLink::lookupCompiled($context, self::SUBSTR_LOGICAL, '#34256');
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_ENCODING_LOGICAL, 'mb_substr_encoding');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34256',
            true
        );
    }
}
