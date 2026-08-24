<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\CachingIteratorJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * CachingIterator::getCache — thin AOT returns `__spl_cache` hashtable (#27421).
 *
 * Requires CIT_FULL_CACHE; otherwise BadMethodCallException like Zend/VM (#34490).
 *
 * php-src: ext/spl/spl_iterators.c — CachingIterator::getCache / CIT_FULL_CACHE
 */
final class CachingIteratorGetCache implements Call
{
    /** php-src CIT_FULL_CACHE */
    private const FULL_CACHE = 0x00000100;

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('CachingIterator::getCache() called without $this');
        }
        // php-src ZEND_PARSE_PARAMETERS_NONE (#30948) — $args[0] is $this.
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'CachingIterator::getCache',
                    0,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'cachingiterator_getcache_argc_cont');

            return VmClassMethod::jitArgcDummyReturn($context);
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
        $flagsSlot = $context->type->object->propertyFetch(
            $objPtr,
            CachingIteratorJitHelper::CLASS_NAME,
            CachingIteratorJitHelper::PROP_FLAGS
        );
        $flags = Variable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $i64 = $context->getTypeFromString('int64');
        $hasFull = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::FULL_CACHE, false)),
            $i64->constInt(0, false)
        );
        $okBb = BasicBlockHelper::append($context, 'cachingiterator_getcache_ok');
        $badBb = BasicBlockHelper::append($context, 'cachingiterator_getcache_nofull');
        $context->builder->branchIf($hasFull, $okBb, $badBb);

        $context->builder->positionAtEnd($badBb);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'BadMethodCallException',
            'CachingIterator does not use a full cache (see CachingIterator::__construct)'
        );
        // emitCatchableClassError terminates badBb; continue on the FULL_CACHE path.
        $context->builder->positionAtEnd($okBb);

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
