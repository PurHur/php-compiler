<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** JIT/AOT link hook — compiles GetoptJitHelper into the module (#3251 phase 2). */
final class Getopt
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\GetoptJitHelper::parse';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('GetoptJitHelper::parse missing after compile (#3251)');
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
        $path = dirname(__DIR__, 3).'/ext/standard/GetoptJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'GetoptJitHelper.php');
        if (null === $block) {
            throw new \LogicException('GetoptJitHelper.php parseAndCompile failed (#3251)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('GetoptJitHelper::parse was not compiled for JIT (#3251)');
        }
    }
}
