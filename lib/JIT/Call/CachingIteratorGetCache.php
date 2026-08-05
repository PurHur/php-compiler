<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * CachingIterator::getCache — thin AOT returns `__spl_cache` hashtable (#27421).
 *
 * php-src: ext/spl/spl_iterators.c — CachingIterator::getCache
 */
final class CachingIteratorGetCache implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('CachingIterator::getCache() called without $this');
        }
        $receiver = $args[0];
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            if (Variable::TYPE_VALUE === $receiver->type) {
                $obj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    JitValueBox::valuePtrFromVariable($context, $receiver)
                );
                $receiver = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            } else {
                throw new \LogicException(
                    'CachingIterator::getCache() expects object receiver, got '
                    .Variable::getStringType($receiver->type)
                );
            }
        }
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'CachingIterator',
            CachingIteratorConstruct::PROP_CACHE
        );
        $loaded = $context->builder->load($slot);
        $htPtr = $context->builder->pointerCast(
            $loaded,
            $context->getTypeFromString('__hashtable__*')
        );
        $out = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $out),
            $htPtr
        );

        return $out;
    }
}
