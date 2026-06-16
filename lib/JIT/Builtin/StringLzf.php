<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for lzf_* — compiles LzfJitHelper into the module (#8805).
 */
final class StringLzf
{
    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\lzf\\LzfJitHelper::compress';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\lzf\\LzfJitHelper::decompress';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function compressHelper(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::COMPRESS_HELPER);
    }

    public static function decompressHelper(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::DECOMPRESS_HELPER);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after compile (#8805)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $needed = [\strtolower(self::COMPRESS_HELPER), \strtolower(self::DECOMPRESS_HELPER)];
        $missing = false;
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).'/ext/lzf/LzfJitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'LzfJitHelper.php');
        if (null === $block) {
            throw new \LogicException('LzfJitHelper.php parseAndCompile failed (#8805)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#8805)');
            }
        }
    }
}
