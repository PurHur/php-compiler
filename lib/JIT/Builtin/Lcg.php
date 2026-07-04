<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for lcg_value() — compiles LcgJitHelper (#3295). */
final class Lcg
{
    private const VALUE = 'PHPCompiler\\ext\\standard\\LcgJitHelper::value';

    public static function ensureLinked(Context $context): void
    {
        self::ensureHelper($context, self::VALUE);
    }

    public static function value(Context $context): \PHPLLVM\Value\Function_
    {
        return self::helperFunction($context, self::VALUE);
    }

    private static function helperFunction(Context $context, string $logical): \PHPLLVM\Value\Function_
    {
        self::ensureHelper($context, $logical);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after compile (#3295)');
        }

        return $fn;
    }

    private static function ensureHelper(Context $context, string $logical): void
    {
        $lc = \strtolower($logical);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).'/ext/standard/LcgJitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'LcgJitHelper.php');
        if (null === $block) {
            throw new \LogicException('LcgJitHelper.php parseAndCompile failed (#3295)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('LcgJitHelper was not compiled for JIT (#3295)');
        }
    }
}
