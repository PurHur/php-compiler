<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitNlLanginfo;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for nl_langinfo() via NlLanginfoJitHelper PHP (#30404).
 *
 * Embed + thin standalone AOT: {@see NlLanginfoJitHelper} via {@see JitVmHelperLink}
 * (gethostname #29364 / fnmatch #30383 shape — `__string__*` ABI, null → bool false).
 * Nested helper compile: `\nl_langinfo` → {@see JitNlLanginfo} thin libc leaf.
 * php-src: ext/standard/nl_langinfo.c — PHP_FUNCTION(nl_langinfo)
 */
final class StringNlLanginfo
{
    private const HELPER_PATH = '/ext/standard/NlLanginfoJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\standard\\NlLanginfoJitHelper::nlLanginfoArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_nl_langinfo';

    private const BRIDGE_ENTRY = 'nl_langinfo_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__value__*` — string or bool false */
    public static function invoke(Context $context, JITVariable $item): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitNlLanginfo::invokeLibcLeaf($context, $item);
        }

        self::ensureLinked($context);
        $itemLong = JitNlLanginfo::jitIntArgI64($context, $item);
        $strOrNull = $context->builder->call(
            $context->lookupFunction(self::ABI),
            $itemLong
        );

        return self::boxStringOrFalse($context, $strOrNull);
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30404'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    /** null `__string__*` → bool false box; else string box. */
    private static function boxStringOrFalse(Context $context, Value $strOrNull): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strOrNull, $strPtr->constNull());
        $falseBb = BasicBlockHelper::append($context, 'nl_langinfo_box_false');
        $strBb = BasicBlockHelper::append($context, 'nl_langinfo_box_str');
        $doneBb = BasicBlockHelper::append($context, 'nl_langinfo_box_done');
        $context->builder->branchIf($isNull, $falseBb, $strBb);

        $context->builder->positionAtEnd($falseBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $falsePtr,
            $i32->constInt(0, false)
        );
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $strSlot = JitValueBox::alloc($context);
        $strVal = JitValueBox::pointer($context, $strSlot);
        $owned = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strOrNull);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $strVal,
            $owned
        );
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($falsePtr, $falseEnd);
        $phi->addIncoming($strVal, $strEnd);

        return $phi;
    }
}
