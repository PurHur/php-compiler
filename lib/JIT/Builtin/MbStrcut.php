<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for mb_strcut() — compiles MbStrcutJitHelper into the module (#4573). */
final class MbStrcut
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbStrcutJitHelper::strcut';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('MbStrcutJitHelper::strcut missing after compile (#4573)');
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
        $path = dirname(__DIR__, 3).'/ext/mbstring/MbStrcutJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'MbStrcutJitHelper.php');
        if (null === $block) {
            throw new \LogicException('MbStrcutJitHelper.php parseAndCompile failed (#4573)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('MbStrcutJitHelper::strcut was not compiled for JIT (#4573)');
        }
    }
}
