<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamLibcHandleKernel;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT embed link for libc stream handle table (#9442, #19745). */
final class StreamLibcHandleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamLibcHandleKernel::ensureLinked($context);
    }

    public static function emitRegisterHandle(Context $context, Value $handle, Value $fpPtr): void
    {
        JitStreamLibcHandleKernel::emitRegisterHandle($context, $handle, $fpPtr);
    }

    public static function emitMarkPopen(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitMarkPopen($context, $handle);
    }

    public static function emitClearHandle(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitClearHandle($context, $handle);
    }

    public static function emitClearLlvmHandleSlot(Context $context, Value $handle): void
    {
        JitStreamLibcHandleKernel::emitClearLlvmHandleSlot($context, $handle);
    }
}
