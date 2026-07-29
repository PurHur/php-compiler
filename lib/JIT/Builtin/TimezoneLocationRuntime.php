<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_timezone_location_ht via TimezoneLocationJitHelper PHP (#9451, #24801).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringBitwiseNot #24513).
 * Replaces zone.tab/stat/snprintf/hashtable LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDateTimeNative}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_location_get)
 */
final class TimezoneLocationRuntime
{
    private const ABI_NAME = '__phpc_timezone_location_ht';

    private const HELPER_PATH = '/ext/standard/TimezoneLocationJitHelper.php';

    private const LOCATION_HELPER = 'PHPCompiler\\ext\\standard\\TimezoneLocationJitHelper::locationHashtable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOCATION_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementLookupBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLookupBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('tzloc_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOCATION_HELPER),
            [$fn->getParam(0)]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24801');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24801'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after TimezoneLocationRuntime bridge (#9451)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
