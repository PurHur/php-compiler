<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::{add,addIndex,updateIndex,append}() for nested php-in-PHP JIT helpers (#16075 / #15642 / #23974).
 *
 * VmPregMatches and other ext/standard helpers build VM hashtables inside nested JIT;
 * write paths must lower to LLVM, not compile lib/VM/HashTable.php (#12910 pattern).
 */
final class HashTableWriteNested implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        switch ($this->methodLc) {
            case 'add':
                if (\count($args) < 3) {
                    throw new \LogicException('add() requires HashTable receiver, key, and value');
                }
                $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
                $keyPtr = JitStringArg::stringPtrFromVariable($context, $args[1]);
                HashTableHelper::setAtKeyCoercingNumericString($context, $ht, $keyPtr, $args[2]);
                break;
            case 'addindex':
            case 'updateindex':
                if (\count($args) < 3) {
                    throw new \LogicException($this->methodLc.'() requires HashTable receiver, index, and value');
                }
                $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
                $index = self::indexAsSizeT($context, $args[1]);
                HashTableHelper::setAtIndex($context, $ht, $index, $args[2]);
                break;
            case 'append':
                if (\count($args) < 2) {
                    throw new \LogicException('append() requires HashTable receiver and value');
                }
                HashTableHelper::addElement(
                    $context,
                    self::receiverAsHashtableVar($context, $args[0]),
                    $args[1],
                    null
                );
                break;
            default:
                throw new \LogicException('HashTableWriteNested does not implement '.$this->methodLc.'()');
        }

        return self::voidResult($context);
    }

    private static function receiverAsHashtableVar(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $receiver;
        }
        $htPtr = HashTableNestedReceiver::hashtableFromReceiver($context, $receiver);

        return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $htPtr);
    }

    private static function indexAsSizeT(Context $context, Variable $index): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_LONG === $index->type && null !== $index->compileTimeLong) {
            return $sizeT->constInt($index->compileTimeLong, false);
        }
        if (Variable::TYPE_VALUE === $index->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $index);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return JitNestedHelperCoerce::i64ToScalar(
                $context,
                JitNestedHelperCoerce::scalarToI64($context, $long, $context->getTypeFromString('int64')),
                $sizeT
            );
        }

        return JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->helper->loadValue($index),
                $context->getTypeFromString('int32')
            ),
            $sizeT
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
