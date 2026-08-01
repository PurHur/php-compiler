<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook — compiles GetoptJitHelper into the module (#3251, #26213).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Checkdate #26196 / Ctype #22626).
 * php-src: ext/standard/php_getopt.c — PHP_FUNCTION(getopt)
 */
final class Getopt
{
    private const HELPER_PATH = '/ext/standard/GetoptJitHelper.php';

    private const PARSE_HELPER = 'PHPCompiler\\ext\\standard\\GetoptJitHelper::parse';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PARSE_HELPER, '#26213');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26213'
        );
    }
}
