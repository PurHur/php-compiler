<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link hook for convert_cyr_string() — compiles ConvertCyrStringJitHelper (#4649, #26395).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StripWhitespace #26351).
 */
final class ConvertCyrString
{
    private const HELPER_PATH = '/ext/standard/ConvertCyrStringJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\ConvertCyrStringJitHelper::convert';

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

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26395');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26395'
        );
    }
}
