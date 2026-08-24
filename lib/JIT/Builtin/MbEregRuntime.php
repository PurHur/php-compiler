<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_ereg*() — compiles MbEregJitHelper (#33811, #34389).
 *
 * php-src: ext/mbstring/php_mbregex.c
 */
final class MbEregRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbEregJitHelper.php';

    private const EREG_MATCH_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::eregMatchArgv';

    private const EREGI_MATCH_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::eregiMatchArgv';

    private const REGS_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::lastRegistersHt';

    private const MATCH_ANCHORED_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::matchAnchoredArgv';

    private const EREG_REPLACE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::eregReplaceArgv';

    private const EREGI_REPLACE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbEregJitHelper::eregiReplaceArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EREG_MATCH_LOGICAL,
        self::EREGI_MATCH_LOGICAL,
        self::REGS_LOGICAL,
        self::MATCH_ANCHORED_LOGICAL,
        self::EREG_REPLACE_LOGICAL,
        self::EREGI_REPLACE_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function eregMatchHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::EREG_MATCH_LOGICAL, '#33811');
    }

    public static function eregiMatchHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::EREGI_MATCH_LOGICAL, '#33811');
    }

    public static function lastRegistersHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::REGS_LOGICAL, '#33811');
    }

    public static function matchAnchoredHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::MATCH_ANCHORED_LOGICAL, '#33811');
    }

    public static function eregReplaceHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::EREG_REPLACE_LOGICAL, '#34389');
    }

    public static function eregiReplaceHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::EREGI_REPLACE_LOGICAL, '#34389');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#33811'
        );
    }
}
