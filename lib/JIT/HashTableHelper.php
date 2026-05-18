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
        $prev = $context->builder->getInsertBlock();
        $done = $prev->insertBasicBlock('range_done');
        $loopHead = $prev->insertBasicBlock('range_head');
        $loopBody = $prev->insertBasicBlock('range_body');
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
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($sizeT, 1, 'fill_i');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $iSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $prev = $context->builder->getInsertBlock();
        $done = $prev->insertBasicBlock('fill_done');
        $loopHead = $prev->insertBasicBlock('fill_head');
        $loopBody = $prev->insertBasicBlock('fill_body');
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
}
