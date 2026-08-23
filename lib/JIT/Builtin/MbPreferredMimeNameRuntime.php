<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_preferred_mime_name() — MbPreferredMimeNameJitHelper (#34298 / #13100).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_preferred_mime_name)
 */
final class MbPreferredMimeNameRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbPreferredMimeNameJitHelper.php';

    private const PREFERRED_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbPreferredMimeNameJitHelper::preferredArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PREFERRED_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function preferredHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PREFERRED_LOGICAL, '#34298');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_preferred_mime_name'
        );
    }
}
