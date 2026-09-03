<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * NestedJIT libc leaves for posix_* — owned by ext/posix (#36204).
 *
 * Implemented in {@code ext/posix/JitPosixNestedKernelsFacade.php}; Builtin Posix*Jit
 * classes must not import {@code ext\posix\JitPosix*Kernel}.
 */
interface PosixNestedJitKernels
{
    public function getgid(Context $context): Value;

    public function getegid(Context $context): Value;

    public function getuid(Context $context): Value;

    public function geteuid(Context $context): Value;

    public function getppid(Context $context): Value;

    public function setsid(Context $context): Value;

    public function setuid(Context $context, Value $uidI64): Value;

    public function setgid(Context $context, Value $gidI64): Value;

    public function seteuid(Context $context, Value $uidI64): Value;

    public function setegid(Context $context, Value $gidI64): Value;

    public function setpgid(Context $context, Value $pidI64, Value $pgidI64): Value;
}
