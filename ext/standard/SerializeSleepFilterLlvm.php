<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableKeyFilterLlvm;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * serialize() __sleep property filter — LLVM, not NestedJIT (#13378).
 *
 * php-src: ext/standard/var.c — php_var_serialize_call_sleep filters get_object_vars
 * by names returned from __sleep(). NestedJIT {@see SerializeSleepNestedJitHelper}
 * segfaults under thin AOT when bridging two HashTable operands (#13378).
 */
final class SerializeSleepFilterLlvm
{
    private static int $seq = 0;

    public static function filterProps(Context $context, Value $allHt, Value $sleepHt): Value
    {
        $allowed = self::allowedKeysFromSleepList($context, $sleepHt);

        return HashTableKeyFilterLlvm::intersectKey($context, $allHt, $allowed);
    }

    private static function allowedKeysFromSleepList(Context $context, Value $sleepHt): Value
    {
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $sleepHt);
        $allowed = HashTableHelper::alloc($context);
        $marker = self::longOneVariable($context);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) (++self::$seq);
        $head = BasicBlockHelper::append($context, 'sleep_allowed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'sleep_allowed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'sleep_allowed_adv_'.$tag);
        $done = BasicBlockHelper::append($context, 'sleep_allowed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $nameVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $nameVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isStr = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );
        $addBlock = BasicBlockHelper::append($context, 'sleep_allowed_add_'.$tag);
        $skipBlock = BasicBlockHelper::append($context, 'sleep_allowed_skip_'.$tag);
        $context->builder->branchIf($isStr, $addBlock, $skipBlock);

        $context->builder->positionAtEnd($addBlock);
        $keyPtr = JitValueBox::readStringOrNull($context, $nameVar);
        HashTableHelper::setAtStringKey($context, $allowed, $keyPtr, $marker);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $allowed;
    }

    private static function longOneVariable(Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->constantFromInteger(1, 'int64')
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::pointer($context, $slot)
        );
    }
}
