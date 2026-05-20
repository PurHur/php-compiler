<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for sprintf() (%s, %d, %f, %%).
 */
final class JitSprintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \LogicException('sprintf() requires at least one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('sprintf() format must be a string in this compiler build');
        }
        $fmt = $context->helper->loadValue($args[0]);
        $numArgs = $argc - 1;
        if (0 === $numArgs) {
            $nullArgv = $context->builder->pointerCast(
                $context->getTypeFromString('int64')->constInt(0, false),
                $context->getTypeFromString('__value__*')
            );

            return $context->builder->call(
                $context->lookupFunction('__compiler_sprintf'),
                $fmt,
                $context->getTypeFromString('int64')->constInt(0, false),
                $nullArgv
            );
        }

        $valueTy = $context->getTypeFromString('__value__');
        $argvSlot = $context->builder->alloca($valueTy, $numArgs, 'sprintf_argv');
        for ($i = 0; $i < $numArgs; ++$i) {
            $slot = $context->builder->inBoundsGEP(
                $argvSlot,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt($i, false)
            );
            self::writeArg($context, $slot, $args[$i + 1]);
        }
        $argvPtr = $context->builder->pointerCast(
            $argvSlot,
            $context->getTypeFromString('__value__*')
        );
        $argcVal = $context->getTypeFromString('int64')->constInt($numArgs, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $argcVal,
            $argvPtr
        );
    }

    private static function writeArg(Context $context, Value $slot, JITVariable $arg): void
    {
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($arg->type) {
            case JITVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                return;
            case JITVariable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($arg));
                return;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->helper->loadValue($arg)
                );
                return;
            case JITVariable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool($context, $slot, $context->helper->loadValue($arg));
                return;
            case JITVariable::TYPE_STRING:
                $str = $context->helper->loadValue($arg);
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                return;
            case JITVariable::TYPE_VALUE:
                JitValueBox::copyFromPointer(
                    $context,
                    $slot,
                    $context->helper->loadValue($arg)
                );
                return;
            default:
                throw new \LogicException(
                    'sprintf() argument must be a scalar value in this compiler build'
                );
        }
    }
}
