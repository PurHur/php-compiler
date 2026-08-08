<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for str_replace()/str_ireplace() array $subject (#4056, php-src string.c). */
final class JitStrReplaceArray
{
    private static int $blockSerial = 0;

    public static function invoke(
        Context $context,
        Value $search,
        Value $replace,
        Variable $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $subject);

        return self::buildReplaceHashTable($context, $ht, $search, $replace, $caseInsensitive, $countSlot);
    }

    private static function buildReplaceHashTable(
        Context $context,
        Value $src,
        Value $search,
        Value $replace,
        bool $caseInsensitive,
        ?Value $countSlot = null
    ): Value {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'str_replace_empty_'.$id);
        $workBlock = BasicBlockHelper::append($context, 'str_replace_work_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'str_replace_ht_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'str_replace_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'str_replace_head_'.$id);
        $check = BasicBlockHelper::append($context, 'str_replace_check_'.$id);
        $replaceBlock = BasicBlockHelper::append($context, 'str_replace_replace_'.$id);
        $skipUnset = BasicBlockHelper::append($context, 'str_replace_skip_unset_'.$id);
        $advance = BasicBlockHelper::append($context, 'str_replace_advance_'.$id);
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
        $context->builder->branchIf($isSet, $replaceBlock, $skipUnset);

        $context->builder->positionAtEnd($replaceBlock);
        // php-src convert_to_string per array subject value (#27165).
        $strPtrTy = $context->getTypeFromString('__string__*');
        $itemResultSlot = $context->builder->alloca($strPtrTy, 1, 'str_replace_arr_item_'.$id);
        $entryBox = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $subject = (new strval())->valueToString(
            $context,
            JitValueBox::pointer($context, $entryBox->value)
        );
        $itemCountSlot = null;
        if (null !== $countSlot) {
            $i64 = $context->getTypeFromString('int64');
            $itemCountSlot = $context->builder->alloca($i64, 1, 'str_replace_arr_cnt_'.$id);
            $context->builder->store($i64->constInt(0, false), $itemCountSlot);
        }
        $replaced = JitStrReplace::replace(
            $context,
            $search,
            $replace,
            $subject,
            $caseInsensitive,
            $itemCountSlot
        );
        if (null !== $countSlot && null !== $itemCountSlot) {
            $i64 = $context->getTypeFromString('int64');
            $context->builder->store(
                $context->builder->addNoSignedWrap(
                    $context->builder->load($countSlot),
                    $context->builder->load($itemCountSlot)
                ),
                $countSlot
            );
        }
        $context->builder->store($replaced, $itemResultSlot);
        $afterItemBlock = BasicBlockHelper::append($context, 'str_replace_arr_after_'.$id);
        $context->builder->branch($afterItemBlock);

        $context->builder->positionAtEnd($afterItemBlock);
        $itemStr = $context->builder->load($itemResultSlot);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $srcIdx,
            $itemStr
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }
}
