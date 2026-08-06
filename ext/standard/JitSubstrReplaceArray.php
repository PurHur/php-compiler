<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for substr_replace() array $string + scalar replace/offset/length (#27648).
 *
 * Mirrors {@see JitStrReplaceArray}: walk packed slots, apply {@see JitSubstrReplace::replace}
 * per string element, preserve indices. php-src: ext/standard/string.c — PHP_FUNCTION(substr_replace)
 * array subject branch.
 */
final class JitSubstrReplaceArray
{
    private static int $blockSerial = 0;

    /**
     * @param Value $replace  __string__*
     * @param Value $offset   int64
     * @param Value $length   int64 (ignored when $hasLength is 0)
     * @param Value $hasLength int32 0/1
     */
    public static function invoke(
        Context $context,
        Variable $string,
        Value $replace,
        Value $offset,
        Value $length,
        Value $hasLength
    ): Value {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $string);

        return self::buildReplaceHashTable($context, $ht, $replace, $offset, $length, $hasLength);
    }

    private static function buildReplaceHashTable(
        Context $context,
        Value $src,
        Value $replace,
        Value $offset,
        Value $length,
        Value $hasLength
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
        $emptyBlock = BasicBlockHelper::append($context, 'substr_replace_arr_empty_'.$id);
        $workBlock = BasicBlockHelper::append($context, 'substr_replace_arr_work_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'substr_replace_arr_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'substr_replace_arr_src_'.$id);
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'substr_replace_arr_head_'.$id);
        $check = BasicBlockHelper::append($context, 'substr_replace_arr_check_'.$id);
        $replaceBlock = BasicBlockHelper::append($context, 'substr_replace_arr_replace_'.$id);
        $skipUnset = BasicBlockHelper::append($context, 'substr_replace_arr_skip_'.$id);
        $advance = BasicBlockHelper::append($context, 'substr_replace_arr_adv_'.$id);
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
        $entry = self::listEntryAt($context, $src, $srcIdx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $doReplaceBlock = BasicBlockHelper::append($context, 'substr_replace_arr_do_'.$id);
        $context->builder->branchIf($isString, $doReplaceBlock, $skipUnset);

        $context->builder->positionAtEnd($doReplaceBlock);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $itemResultSlot = $context->builder->alloca($strPtrTy, 1, 'substr_replace_arr_item_'.$id);
        $subject = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
        $replaced = JitSubstrReplace::replace(
            $context,
            $subject,
            $replace,
            $offset,
            $length,
            $hasLength
        );
        $context->builder->store($replaced, $itemResultSlot);
        $afterItemBlock = BasicBlockHelper::append($context, 'substr_replace_arr_after_'.$id);
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

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }
}
