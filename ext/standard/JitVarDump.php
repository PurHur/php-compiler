<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringVarDump;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for var_dump() (#6709). */
final class JitVarDump
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('var_dump() requires at least one argument');
        }

        StringVarDump::ensureLinked($context);
        foreach ($args as $arg) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $context->builder->call(
                $context->lookupFunction('__compiler_var_dump'),
                $valuePtr
            );
        }

        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }
}
