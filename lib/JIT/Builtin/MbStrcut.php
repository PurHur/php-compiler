<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_strcut() / mb_substr() NestedJIT helpers (#4573 / #27028 / #34256).
 *
 * Both *Argv symbols are listed in COMPILED_HELPERS (peer MbSearchRuntime) so NestedJIT
 * emits private utf8Step helpers for either entrypoint.
 */
final class MbStrcut
{
    private const HELPER_PATH = '/ext/mbstring/MbStrcutJitHelper.php';

    private const STRCUT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::strcutArgv';

    private const SUBSTR_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstrJitHelper::substrArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCUT_LOGICAL,
        self::SUBSTR_LOGICAL,
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

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCUT_LOGICAL,
        self::SUBSTR_LOGICAL,
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
