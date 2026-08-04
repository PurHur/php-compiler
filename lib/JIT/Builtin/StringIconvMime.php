<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_iconv_mime_decode via IconvMimeJitHelper PHP (#27424).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (peer StringChunkSplit #21399).
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_mime_decode)
 */
final class StringIconvMime
{
    private const ABI = '__compiler_iconv_mime_decode';

    private const HELPER_PATH = '/ext/iconv/IconvMimeJitHelper.php';

    private const MIME_DECODE_HELPER = 'PHPCompiler\\ext\\iconv\\IconvMimeJitHelper::mimeDecodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MIME_DECODE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'iconv_mime_decode_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64, $strPtr],
            $strPtr,
            self::MIME_DECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27424'
        );
    }
}
