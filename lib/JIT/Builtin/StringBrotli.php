<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for brotli_* — compiles BrotliJitHelper into the module (#6814, #26668).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle} (VmBrotliNative →
 * BrotliJitHelper) in one NestedJIT scope (peer StringLzf #26649 / ObGzhandler #26331).
 */
final class StringBrotli
{
    private const NATIVE_PATH = '/ext/brotli/VmBrotliNative.php';

    private const HELPER_PATH = '/ext/brotli/BrotliJitHelper.php';

    /**
     * Ordered NestedJIT sources — VmBrotliNative before BrotliJitHelper (#26668).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::NATIVE_PATH,
        self::HELPER_PATH,
    ];

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\brotli\\BrotliJitHelper::compress';

    private const UNCOMPRESS_HELPER = 'PHPCompiler\\ext\\brotli\\BrotliJitHelper::uncompress';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPRESS_HELPER,
        self::UNCOMPRESS_HELPER,
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

    public static function uncompressHelper(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::UNCOMPRESS_HELPER);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26668');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#26668'
        );
    }
}
