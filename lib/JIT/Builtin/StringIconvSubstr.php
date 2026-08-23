<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for iconv_substr NestedJIT helper (#27197 / #34272).
 *
 * Peer {@see MbSubstr}: ensureCompiled + lookupCompiled; call sites use callHelper.
 */
final class StringIconvSubstr
{
    private const HELPER_PATH = '/ext/iconv/IconvStringJitHelper.php';

    private const SUBSTR_LOGICAL = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::substrArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
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

        return JitVmHelperLink::lookupCompiled($context, self::SUBSTR_LOGICAL, '#34272');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34272',
            true
        );
    }
}
