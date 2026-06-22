<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;

/** JIT/AOT link hook for highlight_string() — compiles HighlightJitHelper into the module (#3164, #3447). */
final class Highlight
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HighlightJitHelper::renderString';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Helper LLVM is compiled on first highlight_* lowering (#3164, #3447).
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HighlightJitHelper::renderString missing after compile (#3164)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/standard/HighlightJitHelper.php';
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'HighlightJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HighlightJitHelper.php parseAndCompile failed (#3164)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HighlightJitHelper::renderString was not compiled for JIT (#3164)');
        }
    }
}
