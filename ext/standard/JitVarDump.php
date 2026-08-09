<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringVarDump;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for var_dump() (#6709). */
final class JitVarDump
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            // php-src ext/standard/var.c — ArgumentCountError (#28474); AndAbort for AOT (#27763).
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'var_dump() expects at least 1 argument, 0 given'
            );
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
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
