<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_preferred_mime_name() — MbPreferredMimeNameJitHelper (#34298 / #35275).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_preferred_mime_name)
 */
final class MbPreferredMimeNameRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbPreferredMimeNameJitHelper.php';

    private const ASSERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbPreferredMimeNameJitHelper::assertEncodingArgv';

    private const PREFERRED_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbPreferredMimeNameJitHelper::preferredMimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASSERT_LOGICAL,
        self::PREFERRED_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function assertEncodingHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ASSERT_LOGICAL, '#35275');
    }

    public static function preferredHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PREFERRED_LOGICAL, '#35275');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Skip helper-runtime cache: leaf ABI change vs registry NestedJIT (#35275 / peer #35216).
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_preferred_mime_name',
            true
        );
    }
}
