<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Variable::{null,int,bool,string,float,array}() for nested php-in-PHP JIT helpers (#12910). */
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
        $destPtr = JitValueBox::valuePtrFromVariable($context, $args[0]);

        switch ($this->methodLc) {
            case 'null':
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    $destPtr
                );
                break;
            case 'int':
                if (count($args) < 2) {
                    throw new \LogicException('int() requires an integer value');
                }
                $long = $context->helper->loadValue($args[1]);
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $context->builder->zExt($long, $context->getTypeFromString('int64'))
                );
                break;
            case 'bool':
                if (count($args) < 2) {
                    throw new \LogicException('bool() requires a boolean value');
                }
                $boolVal = $context->helper->loadValue($args[1]);
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $destPtr,
                    $context->builder->zExt($boolVal, $context->getTypeFromString('int8'))
                );
                break;
            case 'string':
                if (count($args) < 2) {
                    throw new \LogicException('string() requires a string value');
                }
                $strPtr = $context->helper->loadValue($args[1]);
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $strPtr
                );
                break;
            case 'float':
                if (count($args) < 2) {
                    throw new \LogicException('float() requires a float value');
                }
                $double = $context->helper->loadValue($args[1]);
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    $double
                );
                break;
            case 'array':
                if (count($args) < 2) {
                    throw new \LogicException('array() requires a HashTable value');
                }
                $htPtr = $context->helper->loadValue($args[1]);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    $htPtr
                );
                break;
            default:
                throw new \LogicException('VariableWriteNested does not implement '.$this->methodLc.'()');
        }

        return self::voidResult($context);
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
