<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for convert_cyr_string() — compiles ConvertCyrStringJitHelper (#4649). */
final class ConvertCyrString
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\ConvertCyrStringJitHelper::convert';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('ConvertCyrStringJitHelper::convert missing after compile (#4649)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).'/ext/standard/ConvertCyrStringJitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ConvertCyrStringJitHelper.php');
        if (null === $block) {
            throw new \LogicException('ConvertCyrStringJitHelper.php parseAndCompile failed (#4649)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('ConvertCyrStringJitHelper::convert was not compiled for JIT (#4649)');
        }
    }
}
