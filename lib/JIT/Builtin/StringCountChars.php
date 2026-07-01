<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\ext\standard\JitCountChars;
use PHPLLVM\Value;

/**
 * JIT/AOT link for count_chars() via CountCharsJitHelper PHP (#14692).
 *
 * Replaces inline LLVM histogram loops in JitCountChars::invoke().
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(count_chars)
 */
final class StringCountChars
{
    private const ABI_ARRAY = 'phpc_count_chars_array';

    private const ABI_STRING = 'phpc_count_chars_string';

    private const HELPER_PATH = '/ext/standard/CountCharsJitHelper.php';

    private const ARRAY_HELPER = 'PHPCompiler\\ext\\standard\\CountCharsJitHelper::arrayArgv';

    private const STRING_HELPER = 'PHPCompiler\\ext\\standard\\CountCharsJitHelper::stringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ARRAY_HELPER,
        self::STRING_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementArray($context);
        self::implementString($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $str, int $mode): Value
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $modeVal = $i64->constInt($mode, false);
        if ($mode >= 3) {
            $raw = $context->builder->call(
                $context->lookupFunction(self::ABI_STRING),
                $str,
                $modeVal
            );

            return JitCountChars::materializeByteStringFromPtr($context, $raw);
        }

        $ht = $context->builder->call(
            $context->lookupFunction(self::ABI_ARRAY),
            $str,
            $modeVal
        );

        return self::boxHashtable($context, $ht);
    }

    private static function boxHashtable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->refcount->addref($ht);

        return $ptr;
    }

    private static function implementArray(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ARRAY,
            'count_chars_array_bridge_entry',
            [$strPtr, $i64],
            $htPtr,
            self::ARRAY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14692'
        );
    }

    private static function implementString(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRING,
            'count_chars_string_bridge_entry',
            [$strPtr, $i64],
            $strPtr,
            self::STRING_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14692'
        );
    }
}
