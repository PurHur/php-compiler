<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_encode_mimeheader()/mb_decode_mimeheader() — MbMimeheaderJitHelper (#34299 / #6038).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_mimeheader|mb_decode_mimeheader)
 */
final class MbMimeheaderRuntime
{
    private const BASE64_PATH = '/ext/standard/Base64JitHelper.php';

    private const HELPER_PATH = '/ext/mbstring/MbMimeheaderJitHelper.php';

    private const ENCODE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbMimeheaderJitHelper::encodeArgv';

    private const DECODE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbMimeheaderJitHelper::decodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_LOGICAL,
        self::DECODE_LOGICAL,
        'PHPCompiler\\ext\\standard\\Base64JitHelper::encodeArgv',
        'PHPCompiler\\ext\\standard\\Base64JitHelper::decodeArgv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function encodeHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::ENCODE_LOGICAL, 'mb_encode_mimeheader');
    }

    public static function decodeHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::DECODE_LOGICAL, 'mb_decode_mimeheader');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::BASE64_PATH, self::HELPER_PATH],
            self::COMPILED_HELPERS,
            'mb_mimeheader',
            true
        );
    }
}
