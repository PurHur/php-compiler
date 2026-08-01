<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for lzf_* — compiles LzfJitHelper into the module (#8805, #26649).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringZstd #26596 / MbStrwidth #26617).
 */
final class StringLzf
{
    private const HELPER_PATH = '/ext/lzf/LzfJitHelper.php';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\lzf\\LzfJitHelper::compress';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\lzf\\LzfJitHelper::decompress';

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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26649');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26649'
        );
    }
}
