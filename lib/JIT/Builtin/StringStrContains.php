<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for str_contains/str_starts_with/str_ends_with via StrContainsJitHelper PHP (#14768).
 *
 * Replaces ~95 LOC inline LLVM in JitStringSearch::contains/startsWith/endsWith.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c
 */
final class StringStrContains
{
    private const ABI_CONTAINS = 'phpc_str_contains';

    private const ABI_STARTS_WITH = 'phpc_str_starts_with';

    private const ABI_ENDS_WITH = 'phpc_str_ends_with';

    private const HELPER_PATH = '/ext/standard/StrContainsJitHelper.php';

    private const CONTAINS_HELPER = 'PHPCompiler\\ext\\standard\\StrContainsJitHelper::containsArgv';

    private const STARTS_WITH_HELPER = 'PHPCompiler\\ext\\standard\\StrContainsJitHelper::startsWithArgv';

    private const ENDS_WITH_HELPER = 'PHPCompiler\\ext\\standard\\StrContainsJitHelper::endsWithArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONTAINS_HELPER,
        self::STARTS_WITH_HELPER,
        self::ENDS_WITH_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementContains($context);
        self::implementStartsWith($context);
        self::implementEndsWith($context);
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
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_STARTS_WITH),
            $haystack,
            $needle
        );
    }

    public static function invokeEndsWith(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ENDS_WITH),
            $haystack,
            $needle
        );
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

    private static function implementStartsWith(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STARTS_WITH,
            'str_starts_with_bridge_entry',
            [$strPtr, $strPtr],
            $i1,
            self::STARTS_WITH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14768'
        );
    }

    private static function implementEndsWith(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ENDS_WITH,
            'str_ends_with_bridge_entry',
            [$strPtr, $strPtr],
            $i1,
            self::ENDS_WITH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14768'
        );
    }
}
