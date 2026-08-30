<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_detect_encoding() — MbDetectEncodingJitHelper (#34358 / #3075).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class MbDetectEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbDetectEncodingJitHelper.php';

    private const DETECT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectEncodingJitHelper::detectArgv';

    private const ABI_DETECT = 'phpc_mb_detect_encoding_detect';

    private const BRIDGE_DETECT = 'mb_detect_encoding_detect_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DETECT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::implementDetect($context);
    }

    public static function detectHelper(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return $context->lookupFunction(self::ABI_DETECT);
    }

    private static function implementDetect(Context $context): void
    {
        if (NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_DETECT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_DETECT)) {
            $context->registerFunction(self::ABI_DETECT, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DETECT,
            self::BRIDGE_DETECT,
            [$strPtr, $strPtr, $i64],
            $strPtr,
            self::DETECT_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35846'
        );
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_detect_encoding'
        );
    }
}
