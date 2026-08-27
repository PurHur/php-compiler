<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitPregReplaceCallback;
use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbEregRuntime;
use PHPCompiler\JIT\Builtin\MbSplitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_ereg() / mb_eregi() / mb_ereg_match() / mb_ereg*_replace() /
 * mb_split() via MbEregJitHelper / MbSplitJitHelper (#33811, #34389, #34391, #35297).
 *
 * Compile-time fold stays in {@see JitMbEregSearch}; runtime uses NestedJIT helper calls
 * (peer {@see JitMbStrSplit} / #26870). 3-arg &$regs via {@see MbEregJitHelper::lastRegistersHt()}.
 *
 * php-src: ext/mbstring/php_mbregex.c
 */
final class JitMbEreg
{
    /**
     * mb_ereg() / mb_eregi() — runtime pattern/string; optional &$regs (#35297 leftover #33811).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeMatch(Context $context, array $args, bool $caseInsensitive): Value
    {
        $fn = $caseInsensitive ? 'mb_eregi' : 'mb_ereg';
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $fn,
            0,
            'pattern'
        );
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $pattern,
            sprintf('%s(): Argument #1 ($pattern) must not be empty', $fn)
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
        BasicBlockHelper::ensureOpenInsertBlock(
            $context,
            $caseInsensitive ? 'mb_eregi_runtime' : 'mb_ereg_runtime'
        );

        $matchFn = $caseInsensitive
            ? MbEregRuntime::eregiMatchHelper($context)
            : MbEregRuntime::eregMatchHelper($context);
        $matchedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            $matchFn,
            [$pattern, $string]
        );

        if (\count($args) >= 3) {
            self::writeEregRegistersRuntime($context, $args[2]);
        }

        return self::boolBoxFromI64($context, $matchedRaw);
    }

    /**
     * Store MbEregJitHelper::$lastMatch into by-ref $regs (peer JitPregMatchEx / #35297).
     */
    private static function writeEregRegistersRuntime(Context $context, JITVariable $regsArg): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_regs_write');
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbEregRuntime::lastRegistersHelper($context),
            []
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $regsArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $outPtr,
            $ht
        );
        JitValueBox::publishAfterWrite($context, $outPtr);
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
     * mb_ereg_replace_callback() — ERE→PCRE then thin preg callback bridge (#35335).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeReplaceCallback(Context $context, array $args): Value
    {
        if (!PregReplaceCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(
                'mb_ereg_replace_callback() callback must be '
                .PregReplaceCallbackPolicy::JIT_SUBSET
                .' for JIT/AOT in this compiler build; '
                .PregReplaceCallbackPolicy::DEFERRED_KINDS.' are deferred (#1177, #35335)'
            );
        }

        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_ereg_replace_callback',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'mb_ereg_replace_callback',
            2,
            'string'
        );
        $optionsPtr = $context->builder->load($context->constantStringFromString(''));
        $hasOptions = $context->getTypeFromString('int64')->constInt(0, false);
        if (\count($args) >= 4) {
            $optionsPtr = JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[3],
                'mb_ereg_replace_callback',
                3,
                'options'
            );
            $hasOptions = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $pcrePattern = self::lowerEregToPcrePattern(
            $context,
            $args[0],
            $pattern,
            $optionsPtr,
            $hasOptions,
            false,
            $args[3] ?? null
        );

        return JitPregReplaceCallback::invoke(
            $context,
            $pcrePattern,
            $args[1],
            $string
        );
    }

    /**
     * Bake or runtime-convert mb ERE pattern to PCRE for preg thin helpers (#35335).
     */
    private static function lowerEregToPcrePattern(
        Context $context,
        JITVariable $patternArg,
        Value $pattern,
        Value $optionsPtr,
        Value $hasOptions,
        bool $caseInsensitive,
        ?JITVariable $optionsArg = null
    ): Value {
        $patternLit = JitStringArg::compileTimeLiteral($patternArg);
        $optionsLit = null !== $optionsArg ? JitStringArg::compileTimeLiteral($optionsArg) : null;
        if (null !== $patternLit && (null === $optionsArg || null !== $optionsLit)) {
            $hasOpt = null !== $optionsLit ? 1 : 0;
            $optStr = $optionsLit ?? '';
            $pcre = MbEregJitHelper::eregToPcrePatternArgv(
                $patternLit,
                $optStr,
                $hasOpt,
                $caseInsensitive ? 1 : 0
            );
            if ('' === $pcre) {
                throw new \LogicException(
                    'mb_ereg_replace_callback(): invalid ERE pattern for JIT/AOT in this compiler build'
                );
            }

            return $context->builder->load($context->constantStringFromString($pcre));
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_replace_cb_pcre');

        $ci = $context->getTypeFromString('int64')->constInt($caseInsensitive ? 1 : 0, false);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbEregRuntime::eregToPcreHelper($context),
            [$pattern, $optionsPtr, $hasOptions, $ci]
        );

        return JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
    }

    /**
     * mb_split() — runtime pattern/string(/limit) (#34391 leftover of #13367).
     *
     * NestedJIT stores parts (no HashTable under thin AOT); LLVM rebuilds packed HT
     * (peer {@see JitMbStrSplit} / preg_split #1178).
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
        $i64 = $context->getTypeFromString('int64');
        $limit = $i64->constInt(-1, true);
        if (\count($args) >= 3) {
            $limit = JitStrictIntArg::lower($context, $args[2], 'mb_split', 3, 'limit');
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_split_runtime');

        $joinedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSplitRuntime::splitJoinedHelper($context),
            [$pattern, $string, $limit]
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_split_after_helper');
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $joinedRaw);

        return self::hashtableFromJoined($context, $joined);
    }

    /**
     * Rebuild HT from RS-joined parts (peer {@see JitMbStrSplit}).
     * Empty joined → []; otherwise explode.
     */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mbspl';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_split_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_split_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_split_joined_done_'.$tag);
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
