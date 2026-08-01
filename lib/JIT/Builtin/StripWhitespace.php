<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link hook for php_strip_whitespace() — compiles StripWhitespaceJitHelper (#3262, #26351).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer JitHelperAbiBridge #26347).
 */
final class StripWhitespace
{
    private const HELPER_PATH = '/ext/standard/StripWhitespaceJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\StripWhitespaceJitHelper::stripString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26351');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26351'
        );
    }
}
