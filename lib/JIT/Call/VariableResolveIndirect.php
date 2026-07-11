<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Variable::resolveIndirect() for nested php-in-PHP JIT helpers (#12910). */
final class VariableResolveIndirect implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('resolveIndirect() requires a Variable receiver');
        }

        return JitValueBox::pointer($context, self::materializeBox($context, $args[0]));
    }

    private static function materializeBox(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_VALUE === $receiver->type && Variable::KIND_VARIABLE === $receiver->kind) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::copyFromPointer($context, $slot, JitValueBox::valuePtrFromVariable($context, $receiver));

            return $slot;
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, JitValueBox::valuePtrFromVariable($context, $receiver));

        return $slot;
    }
}
