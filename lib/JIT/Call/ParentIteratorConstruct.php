<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ParentIterator::__construct — thin AOT keep array-valued entries (#27584).
 *
 * Uses key/value pair export (peer LimitIterator / HashTableSliceLlvm).
 * php-src: ext/spl/spl_iterators.c — ParentIterator::accept / hasChildren
 */
final class ParentIteratorConstruct implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('ParentIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'ParentIterator::__construct() expects exactly 1 argument, 0 given'
            );
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        $filtered = self::filterParents($context, $srcHt);
        $filteredVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $filtered
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'ParentIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $filteredVar, Variable::TYPE_HASHTABLE);

        return self::voidResult($context);
    }

    private static function filterParents(Context $context, Value $srcHt): Value
    {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $dest = HashTableHelper::alloc($context);
        $written = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'parent_it_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'parent_it_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'parent_it_done_'.$tag);
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
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($typeByte, $i8->constInt(0x7f, false)),
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $keep = BasicBlockHelper::append($context, 'parent_it_keep_'.$tag);
        $advance = BasicBlockHelper::append($context, 'parent_it_adv_'.$tag);
        $context->builder->branchIf($isArray, $keep, $advance);

        $context->builder->positionAtEnd($keep);
        self::writeKeyed($context, $dest, $written, $keyVar, $valVar, $tag);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function writeKeyed(
        Context $context,
        Value $dest,
        Value $written,
        Variable $keyVar,
        Variable $valVar,
        string $tag
    ): void {
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $strBb = BasicBlockHelper::append($context, 'parent_it_key_str_'.$tag);
        $intBb = BasicBlockHelper::append($context, 'parent_it_key_int_'.$tag);
        $join = BasicBlockHelper::append($context, 'parent_it_key_join_'.$tag);
        $context->builder->branchIf($isString, $strBb, $intBb);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $str, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($intBb);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $idx = JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64($context, $long, $i64),
            $sizeT
        );
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $written,
            $idx,
            $i64->constInt(1, false)
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);
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
            'ParentIterator::__construct() expects an object, got '
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

/**
 * ParentIterator::accept / hasChildren excess argc (#30956).
 *
 * php-src: ext/spl/spl_iterators.c — ZEND_PARSE_PARAMETERS_NONE.
 * hasChildren ACE cites RecursiveFilterIterator (inherited stub).
 */
final class ParentIteratorArgcMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $function,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->function.'() called without $this');
        }
        $given = max(0, \count($args) - 1);
        if (0 !== $given) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($this->function, 0, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'parent_it_'.strtolower($this->method).'_argc_cont'
            );
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
