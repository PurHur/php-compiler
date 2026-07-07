<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for brotli_* — compiles BrotliJitHelper into the module (#6814).
 */
final class StringBrotli
{
    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\brotli\\BrotliJitHelper::compress';

    private const UNCOMPRESS_HELPER = 'PHPCompiler\\ext\\brotli\\BrotliJitHelper::uncompress';

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

    public static function uncompressHelper(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::UNCOMPRESS_HELPER);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after compile (#6814)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $needed = [\strtolower(self::COMPRESS_HELPER), \strtolower(self::UNCOMPRESS_HELPER)];
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
        $helperPath = \dirname(__DIR__, 3).'/ext/brotli/BrotliJitHelper.php';
        $nativePath = \dirname(__DIR__, 3).'/ext/brotli/VmBrotliNative.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($nativePath), 'VmBrotliNative.php');
        if (null === $block) {
            throw new \LogicException('VmBrotliNative.php parseAndCompile failed (#6814)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        $block = $runtime->parseAndCompile((string) \file_get_contents($helperPath), 'BrotliJitHelper.php');
        if (null === $block) {
            throw new \LogicException('BrotliJitHelper.php parseAndCompile failed (#6814)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT brotli (#6814)');
            }
        }
    }
}
