<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT LimitIterator — HT snapshot at construct + rewind/seek OOB (#26825, #24295, #31621).
 *
 * php-src: ext/spl/spl_iterators.c — spl_limit_it_seek / spl_limit_it_rewind
 *
 * Foreach / iterator_to_array keep walking `__spl_ht` directly; rewind must throw
 * OutOfBoundsException when limit is 0 or offset is past the inner end (Zend parity).
 */
final class LimitIteratorJitHelper
{
    public const PROP_OFFSET = '__spl_li_offset';

    public const PROP_LIMIT = '__spl_li_limit';

    public const PROP_SRC_NUM = '__spl_li_src_num';

    public const CLASS_NAME = 'LimitIterator';

    public static function storeMetadata(
        Context $context,
        Value $obj,
        Value $offset,
        Value $limit,
        Value $srcNum
    ): void {
        self::storeLongPropertyValue($context, $obj, self::PROP_OFFSET, $offset);
        self::storeLongPropertyValue($context, $obj, self::PROP_LIMIT, $limit);
        self::storeLongPropertyValue($context, $obj, self::PROP_SRC_NUM, $srcNum);
    }

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        self::compileRewindOobCheck($context, $receiver);

        return self::voidResult($context);
    }

    public static function compileSeek(Context $context, JITVariable $receiver, JITVariable $posArg): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $pos = JitStrictIntArg::lower($context, $posArg, 'LimitIterator::seek', 1, 'offset');
        $offset = self::loadLongProperty($context, $obj, self::PROP_OFFSET);
        $limit = self::loadLongProperty($context, $obj, self::PROP_LIMIT);
        $srcNum = self::loadLongProperty($context, $obj, self::PROP_SRC_NUM);
        $zero = $i64->constInt(0, false);
        $minusOne = $i64->constInt(-1, false);

        self::emitSeekPositionChecks($context, $pos, $offset, $srcNum);

        $relative = $context->builder->sub($pos, $offset);
        $hasLimit = $context->builder->icmp(Builder::INT_SGE, $limit, $zero);
        $limitZero = $context->builder->icmp(Builder::INT_EQ, $limit, $zero);
        $limitZeroOob = $context->builder->and($hasLimit, $limitZero);
        $pastLimit = $context->builder->icmp(Builder::INT_SGE, $relative, $limit);
        $limitNotUnbounded = $context->builder->icmp(Builder::INT_NE, $limit, $minusOne);
        $limitOob = $context->builder->and($limitNotUnbounded, $context->builder->and($hasLimit, $pastLimit));
        $badLimit = BasicBlockHelper::append($context, 'limit_it_seek_limit_oob');
        $ok = BasicBlockHelper::append($context, 'limit_it_seek_ok');
        $context->builder->branchIf(
            $context->builder->or($limitZeroOob, $limitOob),
            $badLimit,
            $ok
        );
        $context->builder->positionAtEnd($badLimit);
        self::emitBehindOffsetMessage($context, $pos, $offset, $limit);
        $context->builder->positionAtEnd($ok);

        return self::voidResult($context);
    }

    public static function compileRewindOobCheck(Context $context, JITVariable $receiver, bool $forcePending = false): void
    {
        $obj = self::loadObject($context, $receiver);
        $offset = self::loadLongProperty($context, $obj, self::PROP_OFFSET);
        $limit = self::loadLongProperty($context, $obj, self::PROP_LIMIT);
        $srcNum = self::loadLongProperty($context, $obj, self::PROP_SRC_NUM);
        self::emitRewindOobChecks($context, $offset, $limit, $srcNum, $forcePending);
    }

    private static function emitRewindOobChecks(
        Context $context,
        Value $offset,
        Value $limit,
        Value $srcNum,
        bool $forcePending = false
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $hasLimit = $context->builder->icmp(Builder::INT_SGE, $limit, $zero);
        $limitZero = $context->builder->icmp(Builder::INT_EQ, $limit, $zero);
        $limitZeroOob = $context->builder->and($hasLimit, $limitZero);
        $checkPast = BasicBlockHelper::append($context, 'limit_it_rew_past');
        $limitZeroBb = BasicBlockHelper::append($context, 'limit_it_rew_limit0');
        $ok = BasicBlockHelper::append($context, 'limit_it_rew_ok');
        $context->builder->branchIf($limitZeroOob, $limitZeroBb, $checkPast);

        $context->builder->positionAtEnd($limitZeroBb);
        self::emitBehindOffsetMessage($context, $offset, $offset, $limit, $forcePending);
        if ($forcePending) {
            $context->builder->branch($ok);
        }
        $context->builder->positionAtEnd($checkPast);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $offset, $srcNum);
        $pastBb = BasicBlockHelper::append($context, 'limit_it_rew_past_end');
        $context->builder->branchIf($pastEnd, $pastBb, $ok);
        $context->builder->positionAtEnd($pastBb);
        self::emitSeekPositionMessage($context, $offset, $forcePending);
        if ($forcePending) {
            $context->builder->branch($ok);
        }
        $context->builder->positionAtEnd($ok);
    }

    private static function emitSeekPositionChecks(
        Context $context,
        Value $pos,
        Value $offset,
        Value $srcNum,
        bool $forcePending = false
    ): void {
        $below = $context->builder->icmp(Builder::INT_SLT, $pos, $offset);
        $badBelow = BasicBlockHelper::append($context, 'limit_it_seek_below');
        $checkPast = BasicBlockHelper::append($context, 'limit_it_seek_past_chk');
        $ok = BasicBlockHelper::append($context, 'limit_it_seek_pos_ok');
        $context->builder->branchIf($below, $badBelow, $checkPast);
        $context->builder->positionAtEnd($badBelow);
        self::emitBelowOffsetMessage($context, $pos, $offset, $forcePending);
        if ($forcePending) {
            $context->builder->branch($ok);
        }
        $context->builder->positionAtEnd($checkPast);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $srcNum);
        $pastBb = BasicBlockHelper::append($context, 'limit_it_seek_past_end');
        $context->builder->branchIf($pastEnd, $pastBb, $ok);
        $context->builder->positionAtEnd($pastBb);
        self::emitSeekPositionMessage($context, $pos, $forcePending);
        if ($forcePending) {
            $context->builder->branch($ok);
        }
        $context->builder->positionAtEnd($ok);
    }

    private static function emitOutOfBoundsMessage(Context $context, Value $msg, bool $forcePending = false): void
    {
        if (!$forcePending) {
            $handler = TryCatchHelper::resolveThrowHandler($context);
            if (null !== $handler && null !== $handler->dispatchBb) {
                TryCatchHelper::emitCatchableClassErrorWithStringValue($context, 'OutOfBoundsException', $msg);

                return;
            }
        }
        TryCatchHelper::emitPendOutOfBoundsForCaller($context, $msg);
    }

    private static function emitSeekPositionMessage(Context $context, Value $pos, bool $forcePending = false): void
    {
        $prefix = $context->builder->load($context->constantStringFromString('Seek position '));
        $posStr = VmResourceIdString::formatNativeLong($context, $pos);
        $suffix = $context->builder->load($context->constantStringFromString(' is out of range'));
        $msg = JitStringConcat::concat($context, JitStringConcat::concat($context, $prefix, $posStr), $suffix);
        self::emitOutOfBoundsMessage($context, $msg, $forcePending);
    }

    private static function emitBelowOffsetMessage(Context $context, Value $pos, Value $offset, bool $forcePending = false): void
    {
        $p1 = $context->builder->load($context->constantStringFromString('Cannot seek to '));
        $posStr = VmResourceIdString::formatNativeLong($context, $pos);
        $p2 = $context->builder->load($context->constantStringFromString(' which is below the offset '));
        $offStr = VmResourceIdString::formatNativeLong($context, $offset);
        $msg = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, JitStringConcat::concat($context, $p1, $posStr), $p2),
            $offStr
        );
        self::emitOutOfBoundsMessage($context, $msg, $forcePending);
    }

    private static function emitBehindOffsetMessage(
        Context $context,
        Value $pos,
        Value $offset,
        Value $limit,
        bool $forcePending = false
    ): void {
        $p1 = $context->builder->load($context->constantStringFromString('Cannot seek to '));
        $posStr = VmResourceIdString::formatNativeLong($context, $pos);
        $p2 = $context->builder->load($context->constantStringFromString(' which is behind offset '));
        $offStr = VmResourceIdString::formatNativeLong($context, $offset);
        $p3 = $context->builder->load($context->constantStringFromString(' plus count '));
        $limStr = VmResourceIdString::formatNativeLong($context, $limit);
        $msg = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, JitStringConcat::concat($context, $p1, $posStr), $p2),
                    $offStr
                ),
                $p3
            ),
            $limStr
        );
        self::emitOutOfBoundsMessage($context, $msg, $forcePending);
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type || JitValueBox::isValueOperand($receiver)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('LimitIterator JIT method requires an object receiver');
    }

    private static function loadLongProperty(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }
        if (JITVariable::TYPE_VALUE === $slot->type || JitValueBox::isValueOperand($slot)) {
            return $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $slot)
            );
        }

        throw new \LogicException("LimitIterator property {$prop} must be native long");
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $prop,
        Value $i64
    ): void {
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $i64);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            $var,
            JITVariable::TYPE_NATIVE_LONG
        );
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
