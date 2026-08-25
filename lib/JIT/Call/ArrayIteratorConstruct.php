<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ArrayIterator / RecursiveArrayIterator / ArrayObject::__construct — thin AOT (#26783, #26775, #27567).
 *
 * Stores a packed hashtable copy in `__spl_ht` so foreach can walk a real table
 * (php-src ext/spl/spl_array.c — spl_array_object_new_ex / set_array).
 * ArrayObject also persists `$iteratorClass` for getIteratorClass/getIterator (#27567).
 */
final class ArrayIteratorConstruct implements Call
{
    public function __construct(
        private readonly string $className = 'ArrayIterator',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->className.'::__construct() called without $this');
        }
        if (!isset($args[1])) {
            if ('ArrayObject' === $this->className) {
                $receiver = self::objectReceiver($context, $args[0]);
                self::storeFlags($context, $context->helper->loadValue($receiver), $this->className, null);
                self::storeDefaultIteratorClass($context, $receiver);
            } elseif (\PHPCompiler\VM\ArrayObjectJitHelper::isArrayAsPropsClass($this->className)) {
                self::storeFlags(
                    $context,
                    $context->helper->loadValue(self::objectReceiver($context, $args[0])),
                    $this->className,
                    null
                );
            }

            return self::voidResult($context);
        }

        $receiver = self::objectReceiver($context, $args[0]);
        $objPtr = $context->helper->loadValue($receiver);
        $objectType = $context->type->object;
        $slot = $objectType->propertySlotFor($objPtr, $this->className, '__spl_ht');
        // Fresh packed HT (native array → new table; HT arg → copy via spread into alloc).
        // Store like initEmptyHashtableProperties: alloc already owns rc=1. propertyStore would
        // addref again, leaving rc≥2 so reserveAppendSlot COW-orphans `$o[]=` writes (#34748 / re-#27286).
        $src = HashTableHelper::coerceToPackedHashtable($context, $args[1]);
        $ht = HashTableHelper::alloc($context);
        $copy = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
        HashTableHelper::spreadInto($context, $copy, $src);
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($ht, $voidPtr),
            $slot
        );

        self::storeFlags($context, $objPtr, $this->className, $args[2] ?? null);

        if ('ArrayObject' === $this->className) {
            self::storeIteratorClass($context, $receiver, $args[3] ?? null);
        }

        return self::voidResult($context);
    }

    /**
     * Persist SPL_ARRAY_* flags for ARRAY_AS_PROPS property handlers (#33061).
     */
    private static function storeFlags(
        Context $context,
        Value $objPtr,
        string $className,
        ?Variable $flagsArg
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $i64->constInt(0, false);
        if (null !== $flagsArg) {
            if (Variable::TYPE_NATIVE_LONG === $flagsArg->type) {
                $flagsVal = $context->helper->loadValue($flagsArg);
            } elseif (null !== $flagsArg->compileTimeLong) {
                $flagsVal = $i64->constInt((int) $flagsArg->compileTimeLong, false);
            } elseif (Variable::TYPE_VALUE === $flagsArg->type || \PHPCompiler\JIT\JitValueBox::isValueOperand($flagsArg)) {
                $flagsVal = $context->builder->call(
                    $context->lookupFunction('__value__toLong'),
                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $flagsArg)
                );
            }
        }
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $objPtr,
                $className,
                \PHPCompiler\VM\ArrayObjectJitHelper::PROP_FLAGS
            ),
            new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $flagsVal),
            Variable::TYPE_NATIVE_LONG
        );
    }

    private static function storeDefaultIteratorClass(Context $context, Variable $receiver): void
    {
        self::storeIteratorClass($context, $receiver, null);
    }

    private static function storeIteratorClass(Context $context, Variable $receiver, ?Variable $iterClassArg): void
    {
        $objPtr = $context->helper->loadValue($receiver);
        $objectType = $context->type->object;
        $name = 'ArrayIterator';
        $classId = $objectType->lookup('ArrayIterator');
        if (null !== $iterClassArg && null !== $iterClassArg->compileTimeString && '' !== $iterClassArg->compileTimeString) {
            $name = $iterClassArg->compileTimeString;
            $classId = $objectType->lookup($name);
            // Iterator subclasses must carry `__spl_ht` before getIterator allocates (#27567).
            $objectType->defineProperty($classId, \PHPCompiler\VM\ArrayObjectJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
        } elseif (null !== $iterClassArg && Variable::TYPE_STRING === $iterClassArg->type) {
            // Runtime string — store name; getIterator falls back to ArrayIterator if id unset.
            $str = $context->helper->loadValue($iterClassArg);
            $objectType->propertyStore(
                $objectType->propertySlotFor($objPtr, 'ArrayObject', \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS),
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str),
                Variable::TYPE_STRING
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($objPtr, 'ArrayObject', \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS_ID),
                new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($classId, false)
                ),
                Variable::TYPE_NATIVE_LONG
            );

            return;
        }
        $nameStr = $context->builder->load($context->constantStringFromString($name));
        $objectType->propertyStore(
            $objectType->propertySlotFor($objPtr, 'ArrayObject', \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS),
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr),
            Variable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($objPtr, 'ArrayObject', \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS_ID),
            new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt($classId, false)
            ),
            Variable::TYPE_NATIVE_LONG
        );
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
            'ArrayIterator family __construct() receiver must be an object, got '
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
