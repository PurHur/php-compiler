<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for Enum::cases() list guards via EnumCasesJitHelper PHP (#10395).
 *
 * SSOT: {@see \PHPCompiler\VM\EnumSupport::casesList()}, {@see \PHPCompiler\VM\EnumCasesJitHelper}
 */
final class EnumCasesRuntime
{
    private const HELPER_PATH = '/VM/EnumCasesJitHelper.php';

    private const LIST_INDEX_HELPER = 'PHPCompiler\\VM\\EnumCasesJitHelper::listIndexForPosition';

    private const LENGTH_HELPER = 'PHPCompiler\\VM\\EnumCasesJitHelper::casesListLength';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LIST_INDEX_HELPER,
        self::LENGTH_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (
            null !== self::probeLinked($context, '__enum_cases__listIndexForPosition')
            && null !== self::probeLinked($context, '__enum_cases__casesListLength')
        ) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__enum_cases__listIndexForPosition',
            'enum_cases_list_index_bridge_entry',
            [$i64],
            $i64,
            self::LIST_INDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10395'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__enum_cases__casesListLength',
            'enum_cases_list_length_bridge_entry',
            [$i64],
            $i64,
            self::LENGTH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10395'
        );
        $context->builder->clearInsertionPosition();
    }

    public static function callListIndexForPosition(Context $context, Value $position): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__enum_cases__listIndexForPosition');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call($fn, $position);
    }

    public static function callCasesListLength(Context $context, Value $declaredCount): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__enum_cases__casesListLength');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call($fn, $declaredCount);
    }

    private static function probeLinked(Context $context, string $abiName): ?\PHPLLVM\Value\Function_
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return $probe;
        }

        return null;
    }
}
