<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_filter() — match + replace (ext/standard/pcre.c; issue #3250). */
final class JitPregFilter
{
    private static int $blockSerial = 0;

    public static function invoke(
        Context $context,
        Value $pattern,
        Value $replacement,
        Variable $subject,
        ?Value $limit = null
    ): Value {
        StringPregMatch::ensureLinked($context);
        $limitVal = $limit ?? self::unlimitedLimit($context);

        if (Variable::TYPE_STRING === $subject->type) {
            return self::filterString($context, $pattern, $replacement, $subject->value, $limitVal);
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $subject);
        $resultHt = self::buildFilterHashTable($context, $ht, $pattern, $replacement, $limitVal);

        return self::wrapHashTableResult($context, $resultHt);
    }

    private static function unlimitedLimit(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(-1, false);
    }

    private static function filterString(
        Context $context,
        Value $pattern,
        Value $replacement,
        Value $subjectStr,
        Value $limit
    ): Value {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $errorSentinel = $i64->constInt(-1, true);

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match'),
            $pattern,
            $subjectStr
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $errorSentinel);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_filter_str_fail_'.$id);
        $checkBlock = BasicBlockHelper::append($context, 'preg_filter_str_check_'.$id);
        $nomatchBlock = BasicBlockHelper::append($context, 'preg_filter_str_nomatch_'.$id);
        $replaceBlock = BasicBlockHelper::append($context, 'preg_filter_str_replace_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_filter_str_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_filter_str_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $matched = $context->builder->icmp(Builder::INT_EQ, $raw, $i64->constInt(1, false));
        $context->builder->branchIf($matched, $replaceBlock, $nomatchBlock);

        $context->builder->positionAtEnd($replaceBlock);
        // php-src: limit 0 → no replacements → null for string subject (#21655).
        $zeroLimit = $context->builder->icmp(Builder::INT_EQ, $limit, $i64->constInt(0, false));
        $doReplaceBlock = BasicBlockHelper::append($context, 'preg_filter_str_do_replace_'.$id);
        $context->builder->branchIf($zeroLimit, $nomatchBlock, $doReplaceBlock);

        $context->builder->positionAtEnd($doReplaceBlock);
        $replaced = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace'),
            $pattern,
            $replacement,
            $subjectStr,
            $limit
        );
        $replaceFailed = $context->builder->icmp(Builder::INT_EQ, $replaced, $strPtrTy->constNull());
        $context->builder->branchIf($replaceFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $replaced);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nomatchBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function wrapHashTableResult(Context $context, Value $resultHt): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isError = $context->builder->icmp(Builder::INT_EQ, $resultHt, $htPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_filter_arr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_filter_arr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_filter_arr_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $resultHt);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function buildFilterHashTable(
        Context $context,
        Value $src,
        Value $pattern,
        Value $replacement,
        Value $limit
    ): Value {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $i64 = $context->getTypeFromString('int64');
        $errorSentinel = $i64->constInt(-1, true);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtrTy = $context->getTypeFromString('__string__*');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'preg_filter_empty_'.$id);
        $workBlock = BasicBlockHelper::append($context, 'preg_filter_work_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_filter_ht_done_'.$id);
        $errorBlock = BasicBlockHelper::append($context, 'preg_filter_error_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'preg_filter_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'preg_filter_head_'.$id);
        $check = BasicBlockHelper::append($context, 'preg_filter_check_'.$id);
        $matchBlock = BasicBlockHelper::append($context, 'preg_filter_match_'.$id);
        $appendBlock = BasicBlockHelper::append($context, 'preg_filter_append_'.$id);
        $skipUnset = BasicBlockHelper::append($context, 'preg_filter_skip_unset_'.$id);
        $skipNoMatch = BasicBlockHelper::append($context, 'preg_filter_skip_nomatch_'.$id);
        $advance = BasicBlockHelper::append($context, 'preg_filter_advance_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $matchBlock, $skipUnset);

        $context->builder->positionAtEnd($matchBlock);
        // php-src convert_to_string per array subject value (#27164).
        $entryBox = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $subject = (new strval())->valueToString(
            $context,
            JitValueBox::pointer($context, $entryBox->value)
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match'),
            $pattern,
            $subject
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $errorSentinel);
        $context->builder->branchIf($isError, $errorBlock, $appendBlock);

        $context->builder->positionAtEnd($appendBlock);
        $matched = $context->builder->icmp(Builder::INT_EQ, $raw, $i64->constInt(1, false));
        $replaceBlock = BasicBlockHelper::append($context, 'preg_filter_replace_'.$id);
        $context->builder->branchIf($matched, $replaceBlock, $skipNoMatch);

        $context->builder->positionAtEnd($replaceBlock);
        // php-src: limit 0 → skip entry (no replacements; #21655).
        $zeroLimit = $context->builder->icmp(Builder::INT_EQ, $limit, $i64->constInt(0, false));
        $doReplaceBlock = BasicBlockHelper::append($context, 'preg_filter_do_replace_'.$id);
        $context->builder->branchIf($zeroLimit, $skipNoMatch, $doReplaceBlock);

        $context->builder->positionAtEnd($doReplaceBlock);
        $replaced = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace'),
            $pattern,
            $replacement,
            $subject,
            $limit
        );
        $replaceFailed = $context->builder->icmp(Builder::INT_EQ, $replaced, $strPtrTy->constNull());
        $storeBlock = BasicBlockHelper::append($context, 'preg_filter_append_store_'.$id);
        $context->builder->branchIf($replaceFailed, $errorBlock, $storeBlock);

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $srcIdx,
            $replaced
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipNoMatch);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($errorBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);
        $phi->addIncoming($htPtr->constNull(), $errorBlock);

        return $phi;
    }
}
