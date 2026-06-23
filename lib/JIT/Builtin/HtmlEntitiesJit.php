<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT link hook for htmlentities() (#10734). */
final class HtmlEntitiesJit
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntitiesJitHelper::encode';

    public static function encode(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HtmlEntitiesJitHelper::encode missing after compile (#10734)');
        }

        return $context->builder->call($fn, $strPtr, $flags);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/standard/HtmlEntitiesJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'HtmlEntitiesJitHelper.php');
        if (null === $block) {
            throw new \LogicException('HtmlEntitiesJitHelper.php parseAndCompile failed (#10734)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HtmlEntitiesJitHelper::encode was not compiled for JIT (#10734)');
        }
    }
}
