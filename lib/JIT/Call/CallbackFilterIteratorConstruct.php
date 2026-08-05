<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableSliceLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * CallbackFilterIterator::__construct — thin AOT filter into `__spl_ht` (#27259).
 *
 * Walks inner HT via key/value pairs and keeps entries where the Closure callback
 * is truthy (php-src passes current/key/$this; arity-1 arrows ignore extras).
 * Peer RegexIteratorConstruct / ArrayFindLlvm NestedClosureInvoke.
 *
 * php-src: ext/spl/spl_iterators.c — CallbackFilterIterator / spl_cbfilter_it_accept
 */
final class CallbackFilterIteratorConstruct implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('CallbackFilterIterator::__construct() called without $this');
        }
        if (!isset($args[1], $args[2])) {
            throw new \ArgumentCountError(
                'CallbackFilterIterator::__construct() expects exactly 2 arguments, '
                .(\count($args) - 1).' given'
            );
        }

        NestedClosureInvokeLlvm::ensureLinked($context);
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $callback = $args[2];
        if (null === $callback->closureCall
            && Variable::TYPE_OBJECT !== $callback->type
            && Variable::TYPE_VALUE !== $callback->type) {
            throw new \LogicException(
                'CallbackFilterIterator::__construct() thin AOT requires a Closure callback (#27259)'
            );
        }
        $srcHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        $filtered = self::filterWithClosure($context, $srcHt, $callback, $receiver);
        $filteredVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $filtered
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'CallbackFilterIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $filteredVar, Variable::TYPE_HASHTABLE);
        $context->type->object->markObjectConstructed($objPtr);

        return self::voidResult($context);
    }

    private static function filterWithClosure(
        Context $context,
        Value $srcHt,
        Variable $callback,
        Variable $receiver
    ): Value {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
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

        $head = BasicBlockHelper::append($context, 'cbf_it_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'cbf_it_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'cbf_it_done_'.$tag);
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
        // php-src spl_cbfilter_it_accept: callback(current, key, $this).
        // Closures/arrows accept trailing args; named user functions need matching arity.
        $thisObj = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $context->helper->loadValue($receiver)
        );
        $raw = (new NestedClosureInvoke())->call(
            $context,
            $callback,
            $valVar,
            $keyVar,
            $thisObj
        );
        $resultPtr = JitValueBox::normalizeValuePtr($context, $raw);
        $accepted = $context->castToBool($resultPtr);
        $keep = BasicBlockHelper::append($context, 'cbf_it_keep_'.$tag);
        $advance = BasicBlockHelper::append($context, 'cbf_it_adv_'.$tag);
        $context->builder->branchIf($accepted, $keep, $advance);

        $context->builder->positionAtEnd($keep);
        HashTableSliceLlvm::writeKeyed($context, $dest, $written, $keyVar, $valVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        HashTableSliceLlvm::markUnwrittenNullHolesUndefined(
            $context,
            $dest,
            $written,
            $i1->constInt(1, false)
        );

        return $dest;
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
            'CallbackFilterIterator::__construct() expects an object, got '
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
