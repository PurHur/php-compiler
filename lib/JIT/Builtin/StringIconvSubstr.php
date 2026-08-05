<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_iconv_substr via IconvStringJitHelper PHP (#27197).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (peer StringIconvMime #27424).
 * ABI (string, int64 offset, int64 lengthOrOmitted, string) matches ScopeBuiltin extract shape.
 */
final class StringIconvSubstr
{
    private const ABI = '__compiler_iconv_substr';

    private const HELPER_PATH = '/ext/iconv/IconvStringJitHelper.php';

    private const SUBSTR_HELPER = 'PHPCompiler\\ext\\iconv\\IconvStringJitHelper::substrArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUBSTR_HELPER,
    ];

    private const BRIDGE_ENTRY = 'iconv_substr_bridge_entry';

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
            [$strPtr, $i64, $i64, $strPtr],
            $strPtr,
            self::SUBSTR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27197'
        );
    }
}
