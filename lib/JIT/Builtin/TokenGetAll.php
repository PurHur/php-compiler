<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for token_get_all() — TokenGetAllJitHelper PHP (#3171, #24427).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Highlight #24417).
 * php-src: ext/tokenizer/tokenizer.c — PHP_FUNCTION(token_get_all)
 */
final class TokenGetAll
{
    private const HELPER_PATH = '/ext/tokenizer/TokenGetAllJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\tokenizer\\TokenGetAllJitHelper::tokenizeToHashTable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        // Helper LLVM is compiled on first token_get_all() lowering (#3171).
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#24427');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24427'
        );
    }
}
