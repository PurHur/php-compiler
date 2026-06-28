<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Variable::{null,int,bool,string,float,array} for nested php-in-PHP JIT helpers (#13245). */
final class VariableWriteNested implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->methodLc.'() requires a Variable receiver');
        }
        $receiverPtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        match ($this->methodLc) {
            'null' => $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $receiverPtr
            ),
            'int' => $this->writeLong($context, $receiverPtr, $args[1] ?? null),
            'bool' => $this->writeBool($context, $receiverPtr, $args[1] ?? null),
            'string' => $this->writeString($context, $receiverPtr, $args[1] ?? null),
            'float' => $this->writeDouble($context, $receiverPtr, $args[1] ?? null),
            'array' => $this->writeArray($context, $receiverPtr, $args[1] ?? null),
            default => throw new \LogicException('Unsupported Variable write: '.$this->methodLc),
        };

        return self::voidResult($context);
    }

    private function writeLong(Context $context, Value $receiverPtr, ?Variable $value): void
    {
        if (null === $value) {
            throw new \LogicException('int() requires a scalar operand');
        }
        $long = $this->nativeLong($context, $value);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $receiverPtr,
            $long
        );
    }

    private function writeBool(Context $context, Value $receiverPtr, ?Variable $value): void
    {
        if (null === $value) {
            throw new \LogicException('bool() requires a scalar operand');
        }
        $bool = $this->nativeBool($context, $value);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $receiverPtr,
            $bool
        );
    }

    private function writeString(Context $context, Value $receiverPtr, ?Variable $value): void
    {
        if (null === $value) {
            throw new \LogicException('string() requires a scalar operand');
        }
        if (Variable::TYPE_STRING === $value->type) {
            $str = $context->helper->loadValue($value);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $receiverPtr,
                $str
            );

            return;
        }
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $value)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $receiverPtr,
            $str
        );
    }

    private function writeDouble(Context $context, Value $receiverPtr, ?Variable $value): void
    {
        if (null === $value) {
            throw new \LogicException('float() requires a scalar operand');
        }
        $dbl = $this->nativeDouble($context, $value);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $receiverPtr,
            $dbl
        );
    }

    private function writeArray(Context $context, Value $receiverPtr, ?Variable $value): void
    {
        if (null === $value) {
            throw new \LogicException('array() requires a HashTable operand');
        }
        $ht = HashTableHelper::loadHashtablePointer($context, $value);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $receiverPtr,
            $ht
        );
    }

    private function nativeLong(Context $context, Variable $value): Value
    {
        if (Variable::TYPE_NATIVE_LONG === $value->type) {
            return $context->helper->loadValue($value);
        }
        if (Variable::TYPE_VALUE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
        }

        throw new \LogicException('int() operand must be native long or value box');
    }

    private function nativeBool(Context $context, Variable $value): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if (Variable::TYPE_NATIVE_BOOL === $value->type) {
            $raw = $context->helper->loadValue($value);

            return $context->builder->zExt($raw, $i32);
        }
        if (Variable::TYPE_VALUE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readBool'),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
        }

        throw new \LogicException('bool() operand must be native bool or value box');
    }

    private function nativeDouble(Context $context, Variable $value): Value
    {
        if (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            return $context->helper->loadValue($value);
        }
        if (Variable::TYPE_VALUE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
        }

        throw new \LogicException('float() operand must be native double or value box');
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
