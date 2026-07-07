<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;

/** JIT/AOT link hook for hebrevc() — compiles HebrevJitHelper into the module (#17183). */
final class Hebrevc
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HebrevJitHelper::convertWithNewlines';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Helper LLVM is compiled on first hebrevc lowering (#17183).
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HebrevJitHelper::convertWithNewlines missing after compile (#17183)');
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
                throw new \LogicException('HebrevJitHelper.php parseAndCompile failed (#17183)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HebrevJitHelper::convertWithNewlines was not compiled for JIT (#17183)');
        }
    }
}
