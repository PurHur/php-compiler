<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for zstd_* — compiles ZstdJitHelper into the module (#6387, #8564, #8869, #26596).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ConvertCyrString #26395 / MetaTags #26568).
 */
final class StringZstd
{
    private const HELPER_PATH = '/ext/zstd/ZstdJitHelper.php';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\zstd\\ZstdJitHelper::compress';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\zstd\\ZstdJitHelper::decompress';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPRESS_HELPER,
        self::DECOMPRESS_HELPER,
    ];

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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26596');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26596'
        );
    }
}
