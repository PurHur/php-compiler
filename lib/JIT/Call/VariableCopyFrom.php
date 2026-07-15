<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Variable::copyFrom() for nested php-in-PHP JIT helpers (ArrayPushJitHelper #12719). */
final class VariableCopyFrom implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('copyFrom() requires Variable receiver and source Variable');
        }

        $destSlot = JitValueBox::pointer($context, self::destBox($context, $args[0]));
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        JitValueBox::copyFromPointer($context, $destSlot, $srcPtr);

        return HashTableNestedReceiver::nullVariableResult($context);
    }

    private static function destBox(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_VALUE === $receiver->type && Variable::KIND_VARIABLE === $receiver->kind) {
            return $receiver->value;
        }

        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::valuePtrFromVariable($context, $receiver)
        );

        return $slot;
    }
}
