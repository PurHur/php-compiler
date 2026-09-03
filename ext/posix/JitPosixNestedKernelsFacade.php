<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\PosixNestedJitKernels;
use PHPLLVM\Value;

/**
 * NestedJIT libc leaves for lib/JIT/Builtin/Posix*Jit (#36204).
 *
 * php-src: ext/posix/posix.c — thin get/set wrappers. Registered from {@see Module::jitInit}
 * so Builtin files do not import ext/posix JitPosix*Kernel classes.
 */
final class JitPosixNestedKernelsFacade implements PosixNestedJitKernels
{
    public function getgid(Context $context): Value
    {
        return JitPosixGetgidKernel::invoke($context);
    }

    public function getegid(Context $context): Value
    {
        return JitPosixGetegidKernel::invoke($context);
    }

    public function getuid(Context $context): Value
    {
        return JitPosixGetuidKernel::invoke($context);
    }

    public function geteuid(Context $context): Value
    {
        return JitPosixGeteuidKernel::invoke($context);
    }

    public function getppid(Context $context): Value
    {
        return JitPosixGetppidKernel::invoke($context);
    }

    public function setsid(Context $context): Value
    {
        return JitPosixSetsidKernel::invoke($context);
    }

    public function setuid(Context $context, Value $uidI64): Value
    {
        return JitPosixSetuidKernel::invoke($context, $uidI64);
    }

    public function setgid(Context $context, Value $gidI64): Value
    {
        return JitPosixSetgidKernel::invoke($context, $gidI64);
    }

    public function seteuid(Context $context, Value $uidI64): Value
    {
        return JitPosixSeteuidKernel::invoke($context, $uidI64);
    }

    public function setegid(Context $context, Value $gidI64): Value
    {
        return JitPosixSetegidKernel::invoke($context, $gidI64);
    }

    public function setpgid(Context $context, Value $pidI64, Value $pgidI64): Value
    {
        return JitPosixSetpgidKernel::invoke($context, $pidI64, $pgidI64);
    }
}
