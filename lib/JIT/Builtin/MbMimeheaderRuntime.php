<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_encode_mimeheader() / mb_decode_mimeheader() — MbMimeheaderJitHelper (#34299 / #6038).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_mimeheader) / mb_decode_mimeheader
 */
final class MbMimeheaderRuntime
{
    private const ENCODE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbMimeheaderJitHelper::encodeArgv';

    private const DECODE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbMimeheaderJitHelper::decodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_LOGICAL,
        self::DECODE_LOGICAL,
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
        // MimeHeaderConvert before helper — NestedJIT must see encode/decode bodies in-module (#34299).
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [
                '/ext/standard/Base64JitHelper.php',
                '/ext/mbstring/MbMimeheaderJitHelper.php',
            ],
            self::COMPILED_HELPERS,
            'mb_mimeheader'
        );
    }
}
