<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\JitGetcwd;
use PHPCompiler\ext\standard\JitSleep;
use PHPCompiler\JIT\Builtin\PosixCtermidRuntime;
use PHPCompiler\JIT\Builtin\PosixGetegidJit;
use PHPCompiler\JIT\Builtin\PosixGeteuidJit;
use PHPCompiler\JIT\Builtin\PosixGetgidJit;
use PHPCompiler\JIT\Builtin\PosixGetpidJit;
use PHPCompiler\JIT\Builtin\PosixGetppidJit;
use PHPCompiler\JIT\Builtin\PosixGetuidJit;
use PHPCompiler\JIT\Builtin\PosixSessionRuntime;
use PHPCompiler\JIT\Builtin\PosixSetegidJit;
use PHPCompiler\JIT\Builtin\PosixSeteuidJit;
use PHPCompiler\JIT\Builtin\PosixSetgidJit;
use PHPCompiler\JIT\Builtin\PosixSetpgidJit;
use PHPCompiler\JIT\Builtin\PosixSetsidJit;
use PHPCompiler\JIT\Builtin\PosixSetuidJit;
use PHPCompiler\JIT\Builtin\PosixStrerrorRuntime;
use PHPCompiler\JIT\Builtin\PosixTerminalRuntime;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for posix v1 builtins (#7271, #30696, #30728, #30744, #30767, #30803, #30986, #31038, #31066, #31235). */
final class JitPosix
{
    private static int $blockSerial = 0;

    /**
     * posix_getpid() — PHP helper bridge (#30696); NestedJIT reuses getmypid libc leaf.
     *
     * @return Value int64 process id
     */
    public static function getpid(Context $context): Value
    {
        return PosixGetpidJit::invoke($context);
    }

    /**
     * posix_getppid() — PHP helper bridge (#30728); NestedJIT thin getppid(2) leaf.
     *
     * @return Value int64 parent process id
     */
    public static function getppid(Context $context): Value
    {
        return PosixGetppidJit::invoke($context);
    }

    /**
     * posix_geteuid() — PHP helper bridge (#30767); NestedJIT thin geteuid(2) leaf.
     *
     * @return Value int64 effective user id
     */
    public static function geteuid(Context $context): Value
    {
        return PosixGeteuidJit::invoke($context);
    }

    /**
     * posix_getuid() — PHP helper bridge (#30744); NestedJIT thin getuid(2) leaf.
     *
     * @return Value int64 real user id
     */
    public static function getuid(Context $context): Value
    {
        return PosixGetuidJit::invoke($context);
    }

    /**
     * posix_getgid() — PHP helper bridge (#30803); NestedJIT thin getgid(2) leaf.
     *
     * @return Value int64 real group id
     */
    public static function getgid(Context $context): Value
    {
        return PosixGetgidJit::invoke($context);
    }

    /**
     * posix_getegid() — PHP helper bridge (#30986); NestedJIT thin getegid(2) leaf.
     *
     * @return Value int64 effective group id
     */
    public static function getegid(Context $context): Value
    {
        return PosixGetegidJit::invoke($context);
    }

    public static function strerror(Context $context, JITVariable $errnoArg): Value
    {
        return PosixStrerrorRuntime::strerror($context, $errnoArg);
    }

    public static function getLastError(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $i64->constInt(0, false);
    }

    public static function getcwd(Context $context): Value
    {
        $resolved = JitGetcwd::invoke($context);

        return JitGetcwd::boxed($context, $resolved);
    }

    /**
     * posix_setuid() — PHP helper bridge (#31038); NestedJIT thin setuid(2) leaf.
     *
     * @param Value $uidI64 zend long uid (caller: {@see JitLongArg::lower})
     *
     * @return Value i1 — true when setuid succeeds (peer proc_nice #30615)
     */
    public static function setuid(Context $context, Value $uidI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $uid = $uidI64->typeOf() === $i64
            ? $uidI64
            : $context->builder->sext($uidI64, $i64);

        return PosixSetuidJit::invoke($context, $uid);
    }

    /**
     * posix_setgid() — PHP helper bridge (#31066); NestedJIT thin setgid(2) leaf.
     *
     * @param Value $gidI64 zend long gid (caller: {@see JitLongArg::lower})
     *
     * @return Value i1 — true when setgid succeeds (peer posix_setuid #31038)
     */
    public static function setgid(Context $context, Value $gidI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $gid = $gidI64->typeOf() === $i64
            ? $gidI64
            : $context->builder->sext($gidI64, $i64);

        return PosixSetgidJit::invoke($context, $gid);
    }

    /**
     * posix_seteuid() — PHP helper bridge (#31066); NestedJIT thin seteuid(2) leaf.
     *
     * @param Value $uidI64 zend long uid (caller: {@see JitLongArg::lower})
     *
     * @return Value i1 — true when seteuid succeeds (peer posix_setuid #31038)
     */
    public static function seteuid(Context $context, Value $uidI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $uid = $uidI64->typeOf() === $i64
            ? $uidI64
            : $context->builder->sext($uidI64, $i64);

        return PosixSeteuidJit::invoke($context, $uid);
    }

    /**
     * posix_setegid() — PHP helper bridge (#31066); NestedJIT thin setegid(2) leaf.
     *
     * @param Value $gidI64 zend long gid (caller: {@see JitLongArg::lower})
     *
     * @return Value i1 — true when setegid succeeds (peer posix_setuid #31038)
     */
    public static function setegid(Context $context, Value $gidI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $gid = $gidI64->typeOf() === $i64
            ? $gidI64
            : $context->builder->sext($gidI64, $i64);

        return PosixSetegidJit::invoke($context, $gid);
    }

    /** posix_getpgid() — process group ID or false (php-src ext/posix/posix.c; #6505 JIT). */
    public static function getpgid(Context $context, JITVariable $pidArg): Value
    {
        return PosixSessionRuntime::getpgid($context, $pidArg);
    }

    public static function getpgrp(Context $context): Value
    {
        return PosixSessionRuntime::getpgrp($context);
    }

    /**
     * posix_setsid() — PHP helper bridge (#31235); NestedJIT thin setsid(2) leaf.
     *
     * @return Value int64 session id (peer posix_getpid #30696)
     */
    public static function setsid(Context $context): Value
    {
        return PosixSetsidJit::invoke($context);
    }

    /** posix_getsid() — session ID or false (php-src ext/posix/posix.c; #6505 JIT). */
    public static function getsid(Context $context, JITVariable $pidArg): Value
    {
        return PosixSessionRuntime::getsid($context, $pidArg);
    }

    /**
     * posix_setpgid() — PHP helper bridge (#31235); NestedJIT thin setpgid(2) leaf.
     *
     * @return Value boxed bool (peer posix_setuid #31038)
     */
    public static function setpgid(Context $context, JITVariable $pidArg, JITVariable $pgidArg): Value
    {
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_setpgid', 1, 'process_id');
        $pgid = JitSleep::zParamLong($context, $pgidArg, 'posix_setpgid', 2, 'process_group_id');
        $i64 = $context->getTypeFromString('int64');
        $pidI64 = $pid->typeOf() === $i64
            ? $pid
            : $context->builder->sext($pid, $i64);
        $pgidI64 = $pgid->typeOf() === $i64
            ? $pgid
            : $context->builder->sext($pgid, $i64);
        $ok = PosixSetpgidJit::invoke($context, $pidI64, $pgidI64);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    public static function ctermid(Context $context): Value
    {
        return PosixCtermidRuntime::ctermid($context);
    }

    public static function getlogin(Context $context): Value
    {
        return PosixTerminalRuntime::getlogin($context);
    }

    public static function ttyname(Context $context, JITVariable $fdArg): Value
    {
        return PosixTerminalRuntime::ttyname($context, $fdArg);
    }

    public static function isatty(Context $context, JITVariable $fdArg): Value
    {
        return PosixTerminalRuntime::isatty($context, $fdArg);
    }

    /** posix_uname() — hashtable of utsname fields or false (#6123 JIT phase). */
    public static function uname(Context $context): Value
    {
        StringInfo::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->call($context->lookupFunction('__compiler_posix_uname'));
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'posix_uname_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'posix_uname_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'posix_uname_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $i1 = $context->getTypeFromString('int1');
        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->refcount->addref($ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function boxedPidOrFalse(Context $context, Value $raw, string $label): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $rawI32 = $raw->typeOf() === $i32
            ? $raw
            : $context->builder->trunc($raw, $i32);
        $failed = $context->builder->icmp(Builder::INT_SLT, $rawI32, $i32->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, $label.'_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, $label.'_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, $label.'_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $i1 = $context->getTypeFromString('int1');
        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->zExt($rawI32, $i64)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return JitValueBox::pointer($context, $slot);
    }

}
