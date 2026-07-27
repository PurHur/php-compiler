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
            case 'reset':
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    $destPtr
                );
                break;
            case 'int':
                if (count($args) < 2) {
                    throw new \LogicException('int() requires an integer value');
                }
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    self::nativeScalar($context, $args[1], '__value__readLong', 'int64')
                );
                break;
            case 'bool':
                if (count($args) < 2) {
                    throw new \LogicException('bool() requires a boolean value');
                }
                // Direct field stores: the __value__writeBool runtime symbol is
                // declared with an i32 flag param in some modules and i8 in
                // others; JitValueBox::writeBool sidesteps the signature drift.
                JitValueBox::writeBool(
                    $context,
                    $destPtr,
                    self::nativeScalar($context, $args[1], '__value__readLong', 'int8')
                );
                break;
            case 'string':
                if (count($args) < 2) {
                    throw new \LogicException('string() requires a string value');
                }
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    self::nativePointer($context, $args[1], '__value__readString', '__string__*')
                );
                break;
            case 'float':
                if (count($args) < 2) {
                    throw new \LogicException('float() requires a float value');
                }
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    self::nativeScalar($context, $args[1], '__value__readDouble', 'double')
                );
                break;
            case 'array':
                if (count($args) < 2) {
                    throw new \LogicException('array() requires a HashTable value');
                }
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    self::nativePointer($context, $args[1], '__value__readHashtable', '__hashtable__*')
                );
                break;
            default:
                throw new \LogicException('VariableWriteNested does not implement '.$this->methodLc.'()');
        }

        return self::voidResult($context);
    }

    /**
     * Shape-aware scalar arg: box-backed args (TYPE_VALUE or __value__ storage)
     * read through the runtime accessor; native args width-adjust. Blind
     * zExt(loadValue(...)) handed %__value__ structs to i64/i8 parameters
     * (module verify failure, #16565 class).
     */
    private static function nativeScalar(Context $context, Variable $arg, string $reader, string $destTyStr): Value
    {
        $destTy = $context->getTypeFromString($destTyStr);
        if (self::isBoxBacked($context, $arg)) {
            $read = $context->builder->call(
                $context->lookupFunction($reader),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );

            return 'double' === $destTyStr
                ? $read
                : $context->builder->truncOrBitCast($read, $destTy);
        }
        $loaded = $context->helper->loadValue($arg);
        $srcTyStr = $context->getStringFromType($loaded->typeOf());
        if ($srcTyStr === $destTyStr) {
            return $loaded;
        }
        if ('double' === $destTyStr) {
            return $context->builder->siToFp($loaded, $destTy);
        }

        return $context->builder->intCast($loaded, $destTy);
    }

    private static function nativePointer(Context $context, Variable $arg, string $reader, string $destTyStr): Value
    {
        if (self::isBoxBacked($context, $arg)) {
            return $context->builder->call(
                $context->lookupFunction($reader),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        return $context->builder->pointerCast(
            $context->helper->loadValue($arg),
            $context->getTypeFromString($destTyStr)
        );
    }

    private static function isBoxBacked(Context $context, Variable $arg): bool
    {
        if (Variable::TYPE_VALUE === $arg->type || null !== $arg->valueBoxAliasPtr) {
            return true;
        }

        return \in_array(
            $context->getStringFromType($arg->value->typeOf()),
            ['__value__', '__value__*', '__value__value*'],
            true
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
