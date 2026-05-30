<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for constant() (issue #3813). */
final class JitConstant
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        if (JITVariable::TYPE_STRING !== $nameArg->type || null === $nameArg->compileTimeString) {
            throw new \LogicException(
                'constant() constant name must be a string literal in this compiler build'
            );
        }
        if (null === $context->runtime->vmContext) {
            throw new \LogicException('constant() requires VM context');
        }
        $name = $nameArg->compileTimeString;
        $phpVar = $context->runtime->vmContext->constantFetch($name);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null !== $phpVar) {
            self::writeVmVariable($context, $slot, $phpVar->resolveIndirect());

            return $ptr;
        }
        throw new \LogicException('Undefined constant "'.$name.'"');
    }

    private static function writeVmVariable(Context $context, Value $slot, VMVariable $value): void
    {
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($value->type) {
            case VMVariable::TYPE_INTEGER:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->getTypeFromString('int64')->constInt($value->toInt(), false)
                );

                return;
            case VMVariable::TYPE_BOOLEAN:
                JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->constantFromBool($value->toBool())
                );

                return;
            case VMVariable::TYPE_FLOAT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->constantFromFloat($value->toFloat())
                );

                return;
            case VMVariable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $context->builder->load($context->constantStringFromString($value->toString()))
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );

                return;
            case VMVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

                return;
            default:
                throw new \LogicException(
                    'constant() unsupported constant type: '.VMVariable::getStringType($value->type)
                );
        }
    }
}
