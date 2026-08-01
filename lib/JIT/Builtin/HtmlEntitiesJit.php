<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link hook for htmlentities() — compiles HtmlEntitiesJitHelper (#10734, #26417).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GetcwdJit #25541 / ConvertCyrString #26395).
 */
final class HtmlEntitiesJit
{
    private const HELPER_PATH = '/ext/standard/HtmlEntitiesJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntitiesJitHelper::encode';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function encode(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureJitHelperCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26417');

        return $context->builder->call($helperFn, $strPtr, $flags);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26417'
        );
    }
}
