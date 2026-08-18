<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_str_increment/phpc_str_decrement via StrIncdecJitHelper PHP (#14850, #27345)
 * and zend increment_string() (#32435).
 *
 * Replaces ~448 LOC inline LLVM in JitStrIncdec.php.
 * NestedJIT helper inlines the algorithm (no VmString call — thin AOT stub segfault #27345).
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment), PHP_FUNCTION(str_decrement)
 * php-src: Zend/zend_operators.c — increment_string()
 */
final class StringStrIncdec
{
    private const ABI_INCREMENT = 'phpc_str_increment';

    private const ABI_DECREMENT = 'phpc_str_decrement';

    private const ABI_OPERATOR_INCREMENT = 'phpc_increment_string';

    private const ABI_NUMERIC_KIND = 'phpc_string_incdec_kind';

    private const HELPER_PATH = '/ext/standard/StrIncdecJitHelper.php';

    private const INCREMENT_HELPER = 'PHPCompiler\\ext\\standard\\StrIncdecJitHelper::incrementArgv';

    private const DECREMENT_HELPER = 'PHPCompiler\\ext\\standard\\StrIncdecJitHelper::decrementArgv';

    private const OPERATOR_INCREMENT_HELPER = 'PHPCompiler\\ext\\standard\\StrIncdecJitHelper::operatorIncrement';

    private const NUMERIC_KIND_HELPER = 'PHPCompiler\\ext\\standard\\StrIncdecJitHelper::numericIncDecKind';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INCREMENT_HELPER,
        self::DECREMENT_HELPER,
        self::OPERATOR_INCREMENT_HELPER,
        self::NUMERIC_KIND_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementIncrement($context);
        self::implementDecrement($context);
        self::implementOperatorIncrement($context);
        self::implementNumericKind($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeIncrement(Context $context, Value $input): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_INCREMENT),
            $input
        );
    }

    public static function invokeDecrement(Context $context, Value $input): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DECREMENT),
            $input
        );
    }

    /** zend increment_string() — empty/'a'/'z' (not str_increment ValueError). (#32435) */
    public static function invokeOperatorIncrement(Context $context, Value $input): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_OPERATOR_INCREMENT),
            $input
        );
    }

    /** 0=non-numeric, 1=integral, 2=float. (#32435) */
    public static function invokeNumericKind(Context $context, Value $input): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NUMERIC_KIND),
            $input
        );
    }

    private static function implementIncrement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_INCREMENT,
            'str_increment_bridge_entry',
            [$strPtr],
            $strPtr,
            self::INCREMENT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14850'
        );
    }

    private static function implementDecrement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DECREMENT,
            'str_decrement_bridge_entry',
            [$strPtr],
            $strPtr,
            self::DECREMENT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14850'
        );
    }

    private static function implementOperatorIncrement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_OPERATOR_INCREMENT,
            'increment_string_bridge_entry',
            [$strPtr],
            $strPtr,
            self::OPERATOR_INCREMENT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32435'
        );
    }

    private static function implementNumericKind(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NUMERIC_KIND,
            'string_incdec_kind_bridge_entry',
            [$strPtr],
            $i64,
            self::NUMERIC_KIND_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32435'
        );
    }
}
