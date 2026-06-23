<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT link for getcwd() via {@see GetcwdJitHelper} PHP (#10451). */
final class GetcwdJit
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\GetcwdJitHelper::resolveJit';

    public static function invoke(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $helperFn = $context->functions[$lc] ?? null;
        if (null === $helperFn) {
            throw new \LogicException('GetcwdJitHelper::resolveJit missing after compile (#10451)');
        }

        return $context->builder->call($helperFn);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/standard/GetcwdJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'GetcwdJitHelper.php');
        if (null === $block) {
            throw new \LogicException('GetcwdJitHelper.php parseAndCompile failed (#10451)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('GetcwdJitHelper::resolveJit was not compiled for JIT (#10451)');
        }
    }
}
