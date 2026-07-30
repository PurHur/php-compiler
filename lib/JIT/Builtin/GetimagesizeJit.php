<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for getimagesize*() — compiles GetimagesizeJitHelper into the module (#3271, #25527).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringIncludePathResolver #25519).
 */
final class GetimagesizeJit
{
    private const HELPER_PATH = '/ext/standard/GetimagesizeJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::fromBytes';

    private const SHOULD_NOTICE_PATH_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForPath';

    private const SHOULD_NOTICE_BYTES_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForBytes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
        self::SHOULD_NOTICE_PATH_LOGICAL,
        self::SHOULD_NOTICE_BYTES_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#25527');
    }

    public static function shouldNoticeForPathHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SHOULD_NOTICE_PATH_LOGICAL, '#25527');
    }

    public static function shouldNoticeForBytesHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::SHOULD_NOTICE_BYTES_LOGICAL, '#25527');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25527'
        );
    }
}
