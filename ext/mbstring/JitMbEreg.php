<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbEregRuntime;
use PHPCompiler\JIT\Builtin\MbSplitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_ereg() / mb_eregi() / mb_ereg_match() / mb_ereg*_replace() / mb_split()
 * via MbEregJitHelper / MbSplitJitHelper (#33811, #34389, #34391).
 *
 * Compile-time fold stays in {@see JitMbEregSearch}; runtime uses NestedJIT helper calls
 * (peer {@see JitMbStrSplit} / #26870). &$regs write deferred — 3-arg FUNCCALL IR (#33811).
 *
 * php-src: ext/mbstring/php_mbregex.c
 */
final class JitMbEreg
{
    private static int $splitBlockSerial = 0;

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
     * mb_ereg_replace() / mb_eregi_replace() — runtime args (#34389 leftover of #33765/#33656).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeReplace(Context $context, array $args, bool $caseInsensitive): Value
    {
        $fn = $caseInsensitive ? 'mb_eregi_replace' : 'mb_ereg_replace';
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $fn,
            0,
            'pattern'
        );
        $replacement = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            $fn,
            1,
            'replacement'
        );
        $string = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            $fn,
            2,
            'string'
        );
        $optionsPtr = $context->builder->load($context->constantStringFromString(''));
        $hasOptions = $context->getTypeFromString('int64')->constInt(0, false);
        if (\count($args) >= 4) {
            $optionsPtr = JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[3],
                $fn,
                3,
                'options'
            );
            $hasOptions = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $fn.'_runtime');

        $helper = $caseInsensitive
            ? MbEregRuntime::eregiReplaceHelper($context)
            : MbEregRuntime::eregReplaceHelper($context);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helper,
            [$pattern, $replacement, $string, $optionsPtr, $hasOptions]
        );

        return self::boxStringFalseOrNull($context, $raw);
    }

    /**
     * mb_split() — runtime pattern/string(/limit) (#34391 leftover of #13367).
     *
     * Peer {@see JitMbStrSplit}: NestedJIT string peel + JitExplode HT rebuild.
     * Regex-compile failure peels as empty HT (FALSE_SENTINEL starts with NUL → empty strlen
     * path is not used; sentinel is exploded as a single odd part — rare; fold path keeps
     * false for compile-time literals).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeSplit(Context $context, array $args): Value
    {
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_split',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'mb_split',
            1,
            'string'
        );
        $limitEnc = '-1';
        if (\count($args) >= 3) {
            $resolved = self::compileTimeLongLimit($context, $args[2]);
            if (null === $resolved) {
                $limitVal = JitLongArg::lower($context, $args[2], 'mb_split() $limit');
                $lib = $context->llvm->lib;
                if (null !== $lib->LLVMIsAConstantInt($limitVal->value)) {
                    $resolved = (int) $lib->LLVMConstIntGetSExtValue($limitVal->value);
                }
            }
            if (null !== $resolved) {
                $limitEnc = (string) $resolved;
            }
        }
        $limitPtr = $context->builder->load($context->constantStringFromString($limitEnc));

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSplitRuntime::helperFunction($context),
            [$pattern, $string, $limitPtr]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $ht = self::hashtableFromJoined($context, $joined);

        // Box like {@see \PHPCompiler\ext\standard\JitPregSplit} — FUNCCALL temps expect __value__*.
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    /** Empty joined → []; otherwise explode on {@see MbSplitJitHelper::JOIN_DELIM}. */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mbspl'.(string) (++self::$splitBlockSerial);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_split_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_split_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_split_join_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbSplitJitHelper::JOIN_DELIM)
        );
        $ownedJoined = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $joined
        );
        $ht = JitExplode::explode($context, $delim, $ownedJoined);
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function compileTimeLongLimit(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }
        if (JITVariable::TYPE_INTEGER === $var->type && null !== ($var->compileTimeInteger ?? null)) {
            return $var->compileTimeInteger;
        }
        if (null !== ($var->compileTimeLong ?? null)) {
            return (int) $var->compileTimeLong;
        }

        return null;
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

    /**
     * NestedJIT string|false|null → `__value__*` (peer {@see JitMbSearch} / #34211).
     */
    private static function boxStringFalseOrNull(Context $context, Value $raw): Value
    {
        if (JitNestedHelperCoerce::isValueBox($context, $raw)) {
            return JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw);
        }

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
        // Pointer/i64 ABI cannot distinguish null vs false — both become false (rare path).
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
}
