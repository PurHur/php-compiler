<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplObjectStorage Iterator + getInfo/setInfo via `__spl_iter_pos` (#28707).
 *
 * Foreach walks `__objkey_node` and yields object keys (not info values);
 * method protocol shares order via an integer index into `objKeys`.
 *
 * php-src: ext/spl/spl_observer.c — spl_object_storage_* iterator / getInfo
 */
final class SplObjectStorageJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_ITER_POS = '__spl_iter_pos';

    public const CLASS_NAME = 'SplObjectStorage';

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        self::storeLongProperty($context, $obj, 0);

        return self::voidResult($context);
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $context->builder->add($pos, $i64->constInt(1, false)));

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj);
        $sizeT = $context->getTypeFromString('size_t');
        $posSize = $context->builder->truncOrBitCast($pos, $sizeT);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $posSize, $n);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $inRange);

        return $slot;
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    /** current() — object key at iterator position (php-src spl_object_storage_get_current_data). */
    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        return self::compileAtPos($context, $receiver, false);
    }

    /** getInfo() — associated info at iterator position. */
    public static function compileGetInfo(Context $context, JITVariable $receiver): Value
    {
        return self::compileAtPos($context, $receiver, true);
    }

    public static function compileSetInfo(Context $context, JITVariable $receiver, JITVariable $info): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $pos = self::loadLongProperty($context, $obj);
        $node = self::nodeAtPos($context, $ht, $pos);
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $valid = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $okBb = BasicBlockHelper::append($context, 'sos_setinfo_ok');
        $doneBb = BasicBlockHelper::append($context, 'sos_setinfo_done');
        $context->builder->branchIf($valid, $okBb, $doneBb);

        $context->builder->positionAtEnd($okBb);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $info);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return self::voidResult($context);
    }

    /**
     * @param bool $wantInfo true → node value (info); false → node key (object)
     */
    private static function compileAtPos(Context $context, JITVariable $receiver, bool $wantInfo): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $pos = self::loadLongProperty($context, $obj);
        $node = self::nodeAtPos($context, $ht, $pos);
        $out = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $out);
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $valid = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $okBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_ok' : 'sos_cur_ok');
        $badBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_bad' : 'sos_cur_bad');
        $doneBb = BasicBlockHelper::append($context, $wantInfo ? 'sos_info_done' : 'sos_cur_done');
        $context->builder->branchIf($valid, $okBb, $badBb);

        $context->builder->positionAtEnd($okBb);
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        if ($wantInfo) {
            $valField = $context->builder->structGep($node, $nodeMap['value']);
            JitValueBox::copyIntoPointer($context, $destPtr, $valField);
        } else {
            $keyObj = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $destPtr,
                $keyObj
            );
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /** Walk `objKeys` linked list to the pos-th node (null if out of range). */
    private static function nodeAtPos(Context $context, Value $ht, Value $pos): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $head = $context->builder->load($context->builder->structGep($ht, $map['objKeys']));
        $posSize = $context->builder->truncOrBitCast($pos, $sizeT);
        $neg = $context->builder->icmp(Builder::INT_SLT, $pos, $i64->constInt(0, true));

        $pre = BasicBlockHelper::append($context, 'sos_walk_pre');
        $loop = BasicBlockHelper::append($context, 'sos_walk_loop');
        $body = BasicBlockHelper::append($context, 'sos_walk_body');
        $fail = BasicBlockHelper::append($context, 'sos_walk_fail');
        $ok = BasicBlockHelper::append($context, 'sos_walk_ok');
        $merge = BasicBlockHelper::append($context, 'sos_walk_merge');
        $context->builder->branchIf($neg, $fail, $pre);

        $context->builder->positionAtEnd($pre);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $node = $context->builder->phi($nodePtrType);
        $remain = $context->builder->phi($sizeT);
        $node->addIncoming($head, $pre);
        $remain->addIncoming($posSize, $pre);
        $hasNode = $context->builder->icmp(Builder::INT_NE, $node, $nodePtrType->constNull());
        $context->builder->branchIf($hasNode, $body, $fail);

        $context->builder->positionAtEnd($body);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remain, $zero);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $nextRemain = $context->builder->sub($remain, $one);
        $context->builder->branchIf($atTarget, $ok, $loop);
        $node->addIncoming($nextNode, $body);
        $remain->addIncoming($nextRemain, $body);

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($fail);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($nodePtrType);
        $result->addIncoming($node, $ok);
        $result->addIncoming($nodePtrType->constNull(), $fail);

        return $result;
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SplObjectStorage method requires an object receiver');
    }

    private static function storeLongProperty(Context $context, Value $obj, int $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $i64->constInt($value, true));
    }

    private static function storeLongPropertyValue(Context $context, Value $obj, Value $value): void
    {
        $objectType = $context->type->object;
        $slot = $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_ITER_POS);
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value);
        $objectType->propertyStore($slot, $var, JITVariable::TYPE_NATIVE_LONG);
    }

    private static function loadLongProperty(Context $context, Value $obj): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_ITER_POS);

        return $context->helper->loadValue($slot);
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
