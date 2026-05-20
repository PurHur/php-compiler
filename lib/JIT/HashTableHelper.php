<?php

declare(strict_types=1);

/**
 * LLVM helpers for packed-list __hashtable__ (stdlib array builtins).
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class HashTableHelper
{
    public static function alloc(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction('__hashtable__alloc'));
    }

    public static function buildIntegerRange(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): Value {
        $ht = self::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($i64, 1, 'range_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_idx');
        $context->builder->store($start, $iSlot);
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $idxSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $done = BasicBlockHelper::append($context, 'range_done');
        $loopHead = BasicBlockHelper::append($context, 'range_head');
        $loopBody = BasicBlockHelper::append($context, 'range_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $stepPos = $context->builder->icmp(Builder::INT_SGT, $step, $i64->constInt(0, false));
        $condPos = $context->builder->icmp(Builder::INT_SLE, $i, $end);
        $condNeg = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $inRange = $context->builder->select($stepPos, $condPos, $condNeg);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setLong, $ht, $idx, $i);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $step),
            $iSlot
        );
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $ht;
    }

    public static function buildArrayFill(
        Context $context,
        Value $startIndex,
        Value $count,
        Variable $value
    ): Value {
        $ht = self::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($sizeT, 1, 'fill_i');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $iSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $done = BasicBlockHelper::append($context, 'fill_done');
        $loopHead = BasicBlockHelper::append($context, 'fill_head');
        $loopBody = BasicBlockHelper::append($context, 'fill_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $index = $context->builder->addNoSignedWrap($startIndex, $i);
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $setLong,
                    $ht,
                    $index,
                    $context->helper->loadValue($value)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $setString,
                    $ht,
                    $index,
                    $context->helper->loadValue($value)
                );
                break;
            default:
                throw new \LogicException(
                    'array_fill() value type not supported for JIT: '
                    .Variable::getStringType($value->type)
                );
        }
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $ht;
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );
        $entry = $context->builder->inBoundsGep($values, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
    }

    public static function initArray(Context $context, Variable $result): void
    {
        $result->nextFreeElement = 0;
        if ($result->type & Variable::IS_NATIVE_ARRAY) {
            return;
        }
        $ht = self::alloc($context);
        $context->builder->store($ht, $result->value);
    }

    public static function addElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key = null
    ): void {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            self::addNativeElement($context, $array, $element, $key);

            return;
        }
        $ht = $context->helper->loadValue($array);
        $sizeT = $context->getTypeFromString('size_t');
        if (null === $key) {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            self::setAtIndex($context, $ht, $index, $element);

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $keyPtr = $context->helper->loadValue($key);
            self::setAtStringKey($context, $ht, $keyPtr, $element);

            return;
        }
        $index = $context->builder->truncOrBitCast(
            $context->helper->loadValue($key),
            $sizeT
        );
        self::setAtIndex($context, $ht, $index, $element);
    }

    private static function addNativeElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key
    ): void {
        if (null !== $key) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );
        } else {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
        }
        $zero = $context->constantFromInteger(0, 'size_t');
        $slot = $context->builder->inBoundsGep($array->value, $zero, $index);
        $context->builder->store($context->helper->loadValue($element), $slot);
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
        switch ($element->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            default:
                throw new \LogicException(
                    'Array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    private static function setAtStringKey(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyString'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            default:
                throw new \LogicException(
                    'String-key array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }
}
