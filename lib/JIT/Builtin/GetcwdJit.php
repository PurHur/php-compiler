<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for getcwd() via {@see GetcwdJitHelper} PHP (#10451, #25541).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GetimagesizeJit #25527).
 */
final class GetcwdJit
{
    private const HELPER_PATH = '/ext/standard/GetcwdJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\GetcwdJitHelper::resolveJit';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function invoke(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#25541');

        return $context->builder->call($helperFn);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25541'
        );
    }
}
