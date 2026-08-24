<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_split() — compiles MbSplitJitHelper (#34391).
 *
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class MbSplitRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSplitJitHelper.php';

    private const SPLIT_JOINED_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSplitJitHelper::splitJoinedArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SPLIT_JOINED_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function splitJoinedHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SPLIT_JOINED_LOGICAL, '#34391');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34391'
        );
    }
}
