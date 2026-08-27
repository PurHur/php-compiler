<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_http_input() — MbHttpInputJitHelper (#35271).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_http_input)
 */
final class MbHttpInputRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbHttpInputJitHelper.php';

    private const KIND_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbHttpInputJitHelper::kindArgv';

    private const LIST_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbHttpInputJitHelper::listJoinedArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KIND_LOGICAL,
        self::LIST_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function kindHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::KIND_LOGICAL, '#35271');
    }

    public static function listJoinedHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::LIST_LOGICAL, '#35271');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_http_input'
        );
    }
}
