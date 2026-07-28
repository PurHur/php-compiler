<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\VmStringCompare;
use PHPLLVM\Value;

/**
 * JIT/AOT link for str_contains/str_starts_with/str_ends_with (#14768).
 *
 * contains stays on StrContainsJitHelper NestedJIT.
 * starts/ends use libc memcmp via {@see VmStringCompare} — NestedJIT of
 * VmString::startsWith/endsWith compareBytes is always-false under AOT (#24161).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c
 */
final class StringStrContains
{
    private const ABI_CONTAINS = 'phpc_str_contains';

    private const HELPER_PATH = '/ext/standard/StrContainsJitHelper.php';

    private const CONTAINS_HELPER = 'PHPCompiler\\ext\\standard\\StrContainsJitHelper::containsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONTAINS_HELPER,
        'PHPCompiler\\ext\\standard\\StrContainsJitHelper::startsWithArgv',
        'PHPCompiler\\ext\\standard\\StrContainsJitHelper::endsWithArgv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementContains($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeContains(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            $haystack,
            $needle
        );
    }

    public static function invokeStartsWith(Context $context, Value $haystack, Value $needle): Value
    {
        // Ensure libc memcmp is declared (ext/standard/Module registers it).
        try {
            $context->lookupFunction('memcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('memcmp', $ft);
            $context->registerFunction('memcmp', $fn);
        }

        return VmStringCompare::prefixIdentical($context, $haystack, $needle);
    }

    public static function invokeEndsWith(Context $context, Value $haystack, Value $needle): Value
    {
        try {
            $context->lookupFunction('memcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('memcmp', $ft);
            $context->registerFunction('memcmp', $fn);
        }

        return VmStringCompare::suffixIdentical($context, $haystack, $needle);
    }

    private static function implementContains(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CONTAINS,
            'str_contains_bridge_entry',
            [$strPtr, $strPtr],
            $i1,
            self::CONTAINS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14768'
        );
    }
}
