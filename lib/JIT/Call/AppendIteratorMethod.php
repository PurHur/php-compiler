<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * AppendIterator thin-AOT — `__spl_ht` values + parallel `__spl_keys` (#26825, #27312).
 *
 * {@see HashTableHelper::spreadInto} renumbers packed keys, so a second append of `[3]`
 * became key `2` instead of Zend's inner key `0`. Store original keys in `__spl_keys`
 * (sequential) and teach foreach to read them.
 *
 * php-src: ext/spl/spl_iterators.c — AppendIterator
 */
final class AppendIteratorMethod implements Call
{
    public const PROP_KEYS = '__spl_keys';

    private static int $seq = 0;

    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            '__construct' => self::compileConstruct($context, $args),
            'append' => self::compileAppend($context, $args),
            default => throw new \LogicException(
                'AppendIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /** @param list<Variable> $args */
    private static function compileConstruct(Context $context, array $args): Value
    {
        if ([] === $args) {
            throw new \LogicException('AppendIterator::__construct() called without $this');
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $objPtr = $context->helper->loadValue($receiver);
        $empty = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $objPtr,
                'AppendIterator',
                SplOuterIteratorHt::PROP_HT
            ),
            $empty,
            Variable::TYPE_HASHTABLE
        );
        $emptyKeys = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $objPtr,
                'AppendIterator',
                self::PROP_KEYS
            ),
            $emptyKeys,
            Variable::TYPE_HASHTABLE
        );

        return self::voidResult($context);
    }

    /** @param list<Variable> $args */
    private static function compileAppend(Context $context, array $args): Value
    {
        if (!isset($args[0], $args[1])) {
            throw new \ArgumentCountError(
                'AppendIterator::append() expects exactly 1 argument, 0 given'
            );
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $destHtVar = $context->type->object->splBackingHashtable($receiver);
        $keysHtVar = self::keysHashtable($context, $receiver);
        $srcHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        self::appendPreserveKeys(
            $context,
            $context->helper->loadValue($destHtVar),
            $context->helper->loadValue($keysHtVar),
            $srcHt
        );

        return self::voidResult($context);
    }

    /**
     * Append (key,value) pairs from $srcHt onto sequential slots of $destHt / $keysHt.
     */
    private static function appendPreserveKeys(
        Context $context,
        Value $destHt,
        Value $keysHt,
        Value $srcHt
    ): void {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $map = $context->structFieldMap['__hashtable__'];

        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'append_it_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'append_it_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'append_it_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $destNext = $context->builder->load(
            $context->builder->structGep($destHt, $map['nextFreeElement'])
        );
        HashTableHelper::setAtIndex($context, $destHt, $destNext, $valVar);
        HashTableHelper::setAtIndex($context, $keysHt, $destNext, $keyVar);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    public static function keysHashtable(Context $context, Variable $receiver): Variable
    {
        $obj = self::objectReceiver($context, $receiver);
        $objPtr = $context->helper->loadValue($obj);
        $slot = $context->type->object->propertyFetch($objPtr, 'AppendIterator', self::PROP_KEYS);
        if (Variable::TYPE_HASHTABLE === $slot->type) {
            return $slot;
        }

        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );

        return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);
    }

    private static function objectReceiver(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException(
            'AppendIterator method expects an object, got '
            .Variable::getStringType($receiver->type)
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
