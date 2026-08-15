<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for iconv_mime_decode/encode via IconvMimeJitHelper PHP (#27424, #31310).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (peer StringChunkSplit #21399).
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_mime_decode/encode)
 */
final class StringIconvMime
{
    private const ABI_DECODE = '__compiler_iconv_mime_decode';

    private const ABI_ENCODE = '__compiler_iconv_mime_encode';

    private const HELPER_PATH = '/ext/iconv/IconvMimeJitHelper.php';

    private const MIME_DECODE_HELPER = 'PHPCompiler\\ext\\iconv\\IconvMimeJitHelper::mimeDecodeArgv';

    private const MIME_ENCODE_HELPER = 'PHPCompiler\\ext\\iconv\\IconvMimeJitHelper::mimeEncodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MIME_DECODE_HELPER,
        self::MIME_ENCODE_HELPER,
    ];

    private const BRIDGE_ENTRY_DECODE = 'iconv_mime_decode_bridge_entry';

    private const BRIDGE_ENTRY_ENCODE = 'iconv_mime_encode_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implementDecode($context);
    }

    public static function ensureEncodeLinked(Context $context): void
    {
        self::implementEncode($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
        self::ensureEncodeLinked($context);
    }

    private static function implementDecode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_DECODE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY_DECODE)) {
            $context->registerFunction(self::ABI_DECODE, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DECODE,
            self::BRIDGE_ENTRY_DECODE,
            [$strPtr, $i64, $strPtr],
            $strPtr,
            self::MIME_DECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27424'
        );
    }

    private static function implementEncode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_ENCODE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY_ENCODE)) {
            $context->registerFunction(self::ABI_ENCODE, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ENCODE,
            self::BRIDGE_ENTRY_ENCODE,
            [$strPtr, $strPtr, $strPtr],
            $strPtr,
            self::MIME_ENCODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31310'
        );
    }
}
