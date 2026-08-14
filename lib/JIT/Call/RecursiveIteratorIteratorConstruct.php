<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\RecursiveLeavesFlattenRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * RecursiveIteratorIterator::__construct(Traversable $iterator, …) — thin AOT (#26775, #27257).
 *
 * Flattens the inner RecursiveArrayIterator `__spl_ht` into LEAVES_ONLY values (`__spl_ht`)
 * plus parallel original leaf keys (`__spl_keys`) so foreach values and iterator_to_array
 * key overwrites match Zend (php-src ext/spl/spl_iterators.c).
 */
final class RecursiveIteratorIteratorConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('RecursiveIteratorIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'RecursiveIteratorIterator::__construct() expects at least 1 argument, 0 given'
            );
        }

        RecursiveLeavesFlattenRuntime::ensureLinked($context);
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHtVar = $context->type->object->splBackingHashtable($inner);
        $srcHt = $context->helper->loadValue($srcHtVar);
        $flatHt = HashTableHelper::alloc($context);
        $keysHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction(RecursiveLeavesFlattenRuntime::ABI),
            $srcHt,
            $flatHt,
            $keysHt
        );
        $flatVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $flatHt
        );
        $keysVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $keysHt
        );
        $objPtr = $context->helper->loadValue($receiver);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $objPtr,
                'RecursiveIteratorIterator',
                '__spl_ht'
            ),
            $flatVar,
            Variable::TYPE_HASHTABLE
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $objPtr,
                'RecursiveIteratorIterator',
                RecursiveLeavesFlattenRuntime::PROP_KEYS
            ),
            $keysVar,
            Variable::TYPE_HASHTABLE
        );

        return self::voidResult($context);
    }

    public static function keysHashtable(Context $context, Variable $receiver): Variable
    {
        $obj = self::objectReceiver($context, $receiver);
        $objPtr = $context->helper->loadValue($obj);
        $slot = $context->type->object->propertyFetch(
            $objPtr,
            'RecursiveIteratorIterator',
            RecursiveLeavesFlattenRuntime::PROP_KEYS
        );
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
            'RecursiveIteratorIterator::__construct() expects an object, got '
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
 * RecursiveIteratorIterator getDepth / setMaxDepth / getSubIterator argc (#30956).
 *
 * php-src: ext/spl/spl_iterators.c — ZEND_PARSE_PARAMETERS_NONE / optional 1-arg.
 * Thin AOT has no native depth/sub-iterator state (LEAVES_ONLY flatten); valid arity
 * keeps the prior ExternalMethod null. Excess argc is ArgumentCountError.
 */
final class RecursiveIteratorIteratorArgcMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly int $maxUserArgs,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException(
                'RecursiveIteratorIterator::'.$this->method.'() called without $this'
            );
        }
        $function = 'RecursiveIteratorIterator::'.$this->method;
        $given = max(0, \count($args) - 1);
        if (0 === $this->maxUserArgs) {
            if (0 !== $given) {
                ExceptionBridge::emitArgumentCountErrorAndAbort(
                    $context,
                    VmClassMethod::exactUserArgCountMessage($function, 0, $given)
                );
                BasicBlockHelper::ensureOpenInsertBlock(
                    $context,
                    'rii_'.strtolower($this->method).'_argc_cont'
                );
            }
        } elseif ($given > $this->maxUserArgs) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::atMostUserArgCountMessage($function, $this->maxUserArgs, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'rii_'.strtolower($this->method).'_argc_cont'
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
