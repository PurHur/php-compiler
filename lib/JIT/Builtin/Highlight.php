<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for highlight_string() — HighlightJitHelper PHP (#3164, #3447, #24417).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringIdate #24382).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(highlight_string)
 */
final class Highlight
{
    private const HELPER_PATH = '/ext/standard/HighlightJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HighlightJitHelper::renderString';

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
        // Helper LLVM is compiled on first highlight_* lowering (#3164, #3447).
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#24417');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24417'
        );
    }
}
