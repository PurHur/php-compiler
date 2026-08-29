<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_convert_variables() — MbConvertVariablesJitHelper (#35315 / #4572).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbConvertVariablesJitHelper.php';

    private const ENCODING_HELPER_PATH = '/ext/mbstring/MbConvertEncodingJitHelper.php';

    private const DETECT_HELPER_PATH = '/ext/mbstring/MbDetectEncodingJitHelper.php';

    /**
     * Single TU for helper-runtime emit — convertStringArgv must call detect/convert peers
     * in-module; split units leave cross-class static calls unresolved under thin AOT (#35296).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::ENCODING_HELPER_PATH,
        self::DETECT_HELPER_PATH,
        self::HELPER_PATH,
    ];

    private const CONVERT_STRING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertVariablesJitHelper::convertStringArgv';

    private const DETECT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbConvertVariablesJitHelper::detectFromArgv';

    private const ABI_CONVERT_STRING = 'phpc_mb_convert_variables_convert_string';

    private const ABI_DETECT = 'phpc_mb_convert_variables_detect';

    private const BRIDGE_CONVERT_STRING = 'mb_convert_variables_convert_string_bridge_entry';

    private const BRIDGE_DETECT = 'mb_convert_variables_detect_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_STRING_LOGICAL,
        self::DETECT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::implementConvertString($context);
        self::implementDetect($context);
    }

    public static function convertStringHelper(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return $context->lookupFunction(self::ABI_CONVERT_STRING);
    }

    public static function detectHelper(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return $context->lookupFunction(self::ABI_DETECT);
    }

    private static function implementConvertString(Context $context): void
    {
        if (NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_CONVERT_STRING);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_CONVERT_STRING)) {
            $context->registerFunction(self::ABI_CONVERT_STRING, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CONVERT_STRING,
            self::BRIDGE_CONVERT_STRING,
            [$strPtr, $strPtr, $strPtr],
            $strPtr,
            self::CONVERT_STRING_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35315'
        );
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DETECT,
            self::BRIDGE_DETECT,
            [$strPtr, $strPtr, $strPtr],
            $strPtr,
            self::DETECT_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35315'
        );
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            'mb_convert_variables'
        );
    }
}
