<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for preg_replace_callback_array() via PregJitHelper PHP (#3568).
 *
 * Reuses {@see PregMatchRuntime} helper compile cache; SSOT {@see VmPregReplaceCallbackArray}.
 * php-src: ext/pcre/php_pcre.c — PHP_FUNCTION(preg_replace_callback_array)
 */
final class PregReplaceCallbackArrayRuntime
{
    public const ABI_REPLACE_CALLBACK_ARRAY = '__preg_replace_callback_array__invoke';

    private const REPLACE_CALLBACK_ARRAY_HELPER =
        'PHPCompiler\\ext\\standard\\PregJitHelper::replaceCallbackArrayArgv';

    public static function patternsToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    public static function ensureLinked(Context $context): void
    {
        PregMatchRuntime::ensureLinked($context);
        self::implementBridgeIfMissing($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implementBridgeIfMissing(Context $context): void
    {
        $abiName = self::ABI_REPLACE_CALLBACK_ARRAY;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $htPtr, $strPtr)
            );
        $entry = $fn->appendBasicBlock('preg_replace_callback_array_entry');
        $failBb = $fn->appendBasicBlock('preg_replace_callback_array_fail');
        $okBb = $fn->appendBasicBlock('preg_replace_callback_array_ok');
        $context->builder->positionAtEnd($entry);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::REPLACE_CALLBACK_ARRAY_HELPER, '#3568');
        $raw = $context->builder->call(
            $helperFn,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
