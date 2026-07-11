<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for zstd_* — compiles ZstdJitHelper into the module (#6387, #8564, #8869).
 */
final class StringZstd
{
    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\zstd\\ZstdJitHelper::compress';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\zstd\\ZstdJitHelper::decompress';

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
            throw new \LogicException($logical.' missing after compile (#8869)');
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
        $path = \dirname(__DIR__, 3).'/ext/zstd/ZstdJitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ZstdJitHelper.php');
        if (null === $block) {
            throw new \LogicException('ZstdJitHelper.php parseAndCompile failed (#8869)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT zstd (#8869)');
            }
        }
    }
}
