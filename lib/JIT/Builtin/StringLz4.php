<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for lz4_* — compiles Lz4JitHelper into the module (#22529).
 */
final class StringLz4
{
    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\lz4\\Lz4JitHelper::compress';

    private const UNCOMPRESS_HELPER = 'PHPCompiler\\ext\\lz4\\Lz4JitHelper::uncompress';

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
            throw new \LogicException($logical.' missing after compile (#22529)');
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
        $nativePath = \dirname(__DIR__, 3).'/ext/lz4/VmLz4Native.php';
        $helperPath = \dirname(__DIR__, 3).'/ext/lz4/Lz4JitHelper.php';
        $block = $runtime->parseAndCompile((string) \file_get_contents($nativePath), 'VmLz4Native.php');
        if (null === $block) {
            throw new \LogicException('VmLz4Native.php parseAndCompile failed (#22529)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        $block = $runtime->parseAndCompile((string) \file_get_contents($helperPath), 'Lz4JitHelper.php');
        if (null === $block) {
            throw new \LogicException('Lz4JitHelper.php parseAndCompile failed (#22529)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT lz4 (#22529)');
            }
        }
    }
}
