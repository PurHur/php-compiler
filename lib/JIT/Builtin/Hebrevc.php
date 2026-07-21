<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for hebrevc() — compiles HebrevJitHelper into the module (#17183, #21828).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: Hebrev #21828 / StringSubstrCompare #21816.
 * php-src: ext/standard/string.c — historical PHP_FUNCTION(hebrevc) (removed in 8.0).
 */
final class Hebrevc
{
    private const HELPER_PATH = '/ext/standard/HebrevJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HebrevJitHelper::convertWithNewlines';

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
        // Helper LLVM is compiled on first hebrevc lowering (#17183).
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
