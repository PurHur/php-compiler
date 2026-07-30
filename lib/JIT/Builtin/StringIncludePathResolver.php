<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for IncludePathResolver::resolve via IncludePathResolverJitHelper PHP (#816, #25519).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer IncludePathRuntime #20877).
 */
final class StringIncludePathResolver
{
    private const HELPER_PATH = '/ext/standard/IncludePathResolverJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathResolverJitHelper::resolve';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

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

        return JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#25519');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25519'
        );
    }
}
