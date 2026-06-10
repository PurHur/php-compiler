<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for php_strip_whitespace() — compiles StripWhitespaceJitHelper (#3262). */
final class StripWhitespace
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\StripWhitespaceJitHelper::stripString';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('StripWhitespaceJitHelper::stripString missing after compile (#3262)');
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
        $path = dirname(__DIR__, 3).'/ext/standard/StripWhitespaceJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'StripWhitespaceJitHelper.php');
        if (null === $block) {
            throw new \LogicException('StripWhitespaceJitHelper.php parseAndCompile failed (#3262)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('StripWhitespaceJitHelper::stripString was not compiled for JIT (#3262)');
        }
    }
}
