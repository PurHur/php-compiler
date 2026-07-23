<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for lz4_* — compiles Lz4JitHelper into the module (#22529).
 */
final class StringLz4
{
    private const NATIVE_PATH = '/ext/lz4/VmLz4Native.php';

    private const HELPER_PATH = '/ext/lz4/Lz4JitHelper.php';

    private const VM_COMPRESS = 'PHPCompiler\\ext\\lz4\\VmLz4Native::compress';

    private const VM_UNCOMPRESS = 'PHPCompiler\\ext\\lz4\\VmLz4Native::uncompress';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\lz4\\Lz4JitHelper::compress';

    private const UNCOMPRESS_HELPER = 'PHPCompiler\\ext\\lz4\\Lz4JitHelper::uncompress';

    /** @var list<string> */
    private const COMPILED_VM_NATIVE = [
        self::VM_COMPRESS,
        self::VM_UNCOMPRESS,
    ];

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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22602');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::NATIVE_PATH, self::COMPILED_VM_NATIVE, '#22602');
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#22602');
    }
}
