<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for HashContextJitHelper (#3357, #23189).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer UndefinedPropertyFetch #23174).
 */
final class HashContextEmbedBridge
{
    private const HELPER_PATH = '/ext/hash/HashContextJitHelper.php';

    private const INIT_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::init';

    private const UPDATE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::update';

    private const MARK_FINAL_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::markFinalized';

    private const FINALIZE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::finalize';

    private const COPY_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::copy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INIT_HELPER,
        self::UPDATE_HELPER,
        self::FINALIZE_HELPER,
        self::MARK_FINAL_HELPER,
        self::COPY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23189');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23189'
        );
    }
}
