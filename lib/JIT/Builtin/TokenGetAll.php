<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;

/** JIT/AOT link hook for token_get_all() — compiles TokenGetAllJitHelper into the module (#3171). */
final class TokenGetAll
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\tokenizer\\TokenGetAllJitHelper::tokenizeToHashTable';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Helper LLVM is compiled on first token_get_all() lowering (#3171).
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('TokenGetAllJitHelper::tokenizeToHashTable missing after compile (#3171)');
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
        $path = dirname(__DIR__, 3).'/ext/tokenizer/TokenGetAllJitHelper.php';
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $lc): void {
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'TokenGetAllJitHelper.php');
            if (null === $block) {
                throw new \LogicException('TokenGetAllJitHelper.php parseAndCompile failed (#3171)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('TokenGetAllJitHelper::tokenizeToHashTable was not compiled for JIT (#3171)');
        }
    }
}
