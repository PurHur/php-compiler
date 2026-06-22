<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;

/** JIT/AOT link hook for hebrev() — compiles HebrevJitHelper into the module (#3450). */
final class Hebrev
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HebrevJitHelper::convert';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Helper LLVM is compiled on first hebrev lowering (#3450).
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HebrevJitHelper::convert missing after compile (#3450)');
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
        $path = dirname(__DIR__, 3).'/ext/standard/HebrevJitHelper.php';
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'HebrevJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HebrevJitHelper.php parseAndCompile failed (#3450)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HebrevJitHelper::convert was not compiled for JIT (#3450)');
        }
    }
}
