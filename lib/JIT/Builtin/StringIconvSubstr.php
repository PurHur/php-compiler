<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for iconv_strlen/strpos/substr/strrpos NestedJIT helpers (#27197 / #34272 / #34277).
 *
 * Peer {@see MbSubstr} / {@see MbSearchRuntime}: ensureCompiled + lookupCompiled; call sites use callHelper.
 */
final class StringIconvSubstr
{
    private const HELPER_PATH = '/ext/iconv/IconvStringJitHelper.php';

    private const SUBSTR_LOGICAL = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::substrArgv';

    private const STRLEN_LOGICAL = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::strlenArgv';

    private const STRPOS_LOGICAL = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::strposArgv';

    private const STRRPOS_LOGICAL = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::strrposArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUBSTR_LOGICAL,
        self::STRLEN_LOGICAL,
        self::STRPOS_LOGICAL,
        self::STRRPOS_LOGICAL,
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

    public static function strlenHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRLEN_LOGICAL, '#34277');
    }

    public static function strposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRPOS_LOGICAL, '#34277');
    }

    public static function strrposHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRRPOS_LOGICAL, '#34277');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34277',
            true
        );
    }
}
