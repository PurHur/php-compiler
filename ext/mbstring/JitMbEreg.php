<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbEregRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_ereg*() via MbEregJitHelper (#33811 / #34389).
 *
 * Compile-time fold stays in {@see JitMbEregSearch}; runtime uses NestedJIT helper calls
 * (peer {@see JitMbStrSplit} / #26870). &$regs write deferred — 3-arg FUNCCALL IR (#33811).
 *
 * php-src: ext/mbstring/php_mbregex.c
 */
final class JitMbEreg
{
    /**
     * mb_ereg() / mb_eregi() — runtime pattern/string (2-arg path; &$regs follow-up).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeMatch(Context $context, array $args, bool $caseInsensitive): Value
    {
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $caseInsensitive ? 'mb_eregi' : 'mb_ereg',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            $caseInsensitive ? 'mb_eregi' : 'mb_ereg',
            1,
            'string'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $matchFn = $caseInsensitive
            ? MbEregRuntime::eregiMatchHelper($context)
            : MbEregRuntime::eregMatchHelper($context);
        $matchedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            $matchFn,
            [$pattern, $string]
        );

        return self::boolBoxFromI64($context, $matchedRaw);
    }

    /**
     * mb_ereg_match() — 2–3 args, anchored bool.
     *
     * @param list<JITVariable> $args
     */
    public static function invokeMatchAnchored(Context $context, array $args): Value
    {
        $pattern = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'mb_ereg_match',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'mb_ereg_match',
            1,
            'string'
        );
        $optionsPtr = $context->builder->load($context->constantStringFromString(''));
        $hasOptions = $context->getTypeFromString('int64')->constInt(0, false);
        if (\count($args) >= 3 && JITVariable::TYPE_NULL !== $args[2]->type && !$args[2]->isNullConstant) {
            $optionsPtr = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[2],
                'mb_ereg_match',
                2,
                'options'
            );
            $hasOptions = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $matchedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbEregRuntime::matchAnchoredHelper($context),
            [$pattern, $string, $optionsPtr, $hasOptions]
        );

        return self::boolBoxFromI64($context, $matchedRaw);
    }

    /**
     * mb_ereg_replace() / mb_eregi_replace() — runtime pattern/replacement/string (#34389).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeReplace(Context $context, array $args, bool $caseInsensitive): Value
    {
        $fn = $caseInsensitive ? 'mb_eregi_replace' : 'mb_ereg_replace';
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $fn, 0, 'pattern');
        $replacement = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], $fn, 1, 'replacement');
        $string = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], $fn, 2, 'string');
        $optionsPtr = $context->builder->load($context->constantStringFromString(''));
        $hasOptions = $context->getTypeFromString('int64')->constInt(0, false);
        if (\count($args) >= 4 && JITVariable::TYPE_NULL !== $args[3]->type && !$args[3]->isNullConstant) {
            $optionsPtr = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[3], $fn, 3, 'options');
            $hasOptions = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $replaceFn = $caseInsensitive
            ? MbEregRuntime::eregiReplaceHelper($context)
            : MbEregRuntime::eregReplaceHelper($context);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $replaceFn,
            [$pattern, $replacement, $string, $optionsPtr, $hasOptions]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * NestedJIT string|false → `__value__*` (peer {@see JitMbSearch} / #34211).
     */
    private static function boxStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_replace_box');
        $i32 = $context->getTypeFromString('int32');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $missBb = BasicBlockHelper::append($context, 'mb_ereg_replace_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_ereg_replace_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_ereg_replace_done');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function boolBoxFromI64(Context $context, Value $matchedRaw): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $matchedI1 = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $matchedRaw,
            $i64->constInt(0, false)
        );
        JitValueBox::writeBool($context, $slot, $matchedI1);

        return JitValueBox::pointer($context, $slot);
    }
}
