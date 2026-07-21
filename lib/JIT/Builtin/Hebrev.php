<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for hebrev() — compiles HebrevJitHelper into the module (#3450, #21828).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringSubstrCompare #21816.
 * php-src: ext/standard/string.c — PHP_FUNCTION(hebrev).
 */
final class Hebrev
{
    private const HELPER_PATH = '/ext/standard/HebrevJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HebrevJitHelper::convert';

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
        // Helper LLVM is compiled on first hebrev lowering (#3450).
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#21828');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#21828');
    }
}
