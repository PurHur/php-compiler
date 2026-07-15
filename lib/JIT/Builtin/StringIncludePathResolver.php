<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** JIT/AOT link for IncludePathResolver::resolve via IncludePathResolverJitHelper PHP (#816). */
final class StringIncludePathResolver
{
    private const HELPER_PATH = '/ext/standard/IncludePathResolverJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathResolverJitHelper::resolve';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::RESOLVE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::RESOLVE_HELPER.' missing after IncludePathResolverJitHelper compile (#816)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::RESOLVE_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'IncludePathResolverJitHelper.php');
        if (null === $block) {
            throw new \LogicException('IncludePathResolverJitHelper.php parseAndCompile failed (#816)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(self::RESOLVE_HELPER.' was not compiled for JIT (#816)');
        }
    }
}
