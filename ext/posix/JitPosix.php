<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\JitGetcwd;
use PHPCompiler\ext\standard\JitSleep;
use PHPCompiler\JIT\Builtin\PosixCtermidRuntime;
use PHPCompiler\JIT\Builtin\PosixSessionRuntime;
use PHPCompiler\JIT\Builtin\PosixStrerrorRuntime;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for posix v1 builtins (#7271). */
final class JitPosix
{
    private static int $blockSerial = 0;

    public static function getpid(Context $context): Value
    {
        self::ensureLibcPid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getpid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function getppid(Context $context): Value
    {
        self::ensureLibcPid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getppid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function geteuid(Context $context): Value
    {
        self::ensureLibcEuid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('geteuid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function getegid(Context $context): Value
    {
        self::ensureLibcEgid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getegid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
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

    public static function setuid(Context $context, JITVariable $arg): Value
    {
        return self::setId($context, 'setuid', 'posix_setuid', $arg, 'uid');
    }

    public static function setgid(Context $context, JITVariable $arg): Value
    {
        return self::setId($context, 'setgid', 'posix_setgid', $arg, 'gid');
    }

    public static function seteuid(Context $context, JITVariable $arg): Value
    {
        return self::setId($context, 'seteuid', 'posix_seteuid', $arg, 'uid');
    }

    public static function setegid(Context $context, JITVariable $arg): Value
    {
        return self::setId($context, 'setegid', 'posix_setegid', $arg, 'gid');
    }

    /** posix_getpgid() — process group ID or false (php-src ext/posix/posix.c; #6505 JIT). */
    public static function getpgid(Context $context, JITVariable $pidArg): Value
    {
        return PosixSessionRuntime::getpgid($context, $pidArg);
    }

    /** Standalone AOT libc getpgid LLVM quarantine (#12685). */
    public static function getpgidStandalone(Context $context, JITVariable $pidArg): Value
    {
        self::ensureLibcPidLookup($context, 'getpgid');
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_getpgid', 1, 'pid');
        $i32 = $context->getTypeFromString('int32');
        $raw = $context->builder->call(
            $context->lookupFunction('getpgid'),
            $context->builder->trunc($pid, $i32)
        );

        return self::boxedPidOrFalse($context, $raw, 'posix_getpgid');
    }

    /** posix_setsid() — create session (php-src ext/posix/posix.c; #9218 JIT). */
    public static function setsid(Context $context): Value
    {
        self::ensureLibcSetsid($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('setsid'));
        $rawI32 = $raw->typeOf() === $i32
            ? $raw
            : $context->builder->trunc($raw, $i32);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $context->builder->sext($rawI32, $i64));

        return JitValueBox::pointer($context, $slot);
    }

    /** posix_getsid() — session ID or false (php-src ext/posix/posix.c; #6505 JIT). */
    public static function getsid(Context $context, JITVariable $pidArg): Value
    {
        return PosixSessionRuntime::getsid($context, $pidArg);
    }

    /** Standalone AOT libc getsid LLVM quarantine (#12685). */
    public static function getsidStandalone(Context $context, JITVariable $pidArg): Value
    {
        self::ensureLibcPidLookup($context, 'getsid');
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_getsid', 1, 'pid');
        $i32 = $context->getTypeFromString('int32');
        $raw = $context->builder->call(
            $context->lookupFunction('getsid'),
            $context->builder->trunc($pid, $i32)
        );

        return self::boxedPidOrFalse($context, $raw, 'posix_getsid');
    }

    /** posix_setpgid() — set process group (php-src ext/posix/posix.c; #6505 JIT). */
    public static function setpgid(Context $context, JITVariable $pidArg, JITVariable $pgidArg): Value
    {
        self::ensureLibcSetpgid($context);
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_setpgid', 1, 'process_id');
        $pgid = JitSleep::zParamLong($context, $pgidArg, 'posix_setpgid', 2, 'process_group_id');
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('setpgid'),
            $context->builder->trunc($pid, $i32),
            $context->builder->trunc($pgid, $i32)
        );
        $slot = JitValueBox::alloc($context);
        $isTrue = $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $isTrue);

        return JitValueBox::pointer($context, $slot);
    }

    public static function ctermid(Context $context): Value
    {
        return PosixCtermidRuntime::ctermid($context);
    }

    /** Standalone AOT libc ctermid LLVM quarantine (#12684). */
    public static function ctermidStandalone(Context $context): Value
    {
        self::ensureLibcCtermid($context);
        $i8p = $context->getTypeFromString('int8*');
        $msgPtr = $context->builder->call(
            $context->lookupFunction('ctermid'),
            $i8p->constNull()
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $msgPtr
        );
        $lenI64 = $context->builder->zExt($len, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $msgPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );

        return $ptr;
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

    private static function setId(
        Context $context,
        string $libcFn,
        string $phpFn,
        JITVariable $arg,
        string $paramName
    ): Value {
        self::ensureLibcSetId($context, $libcFn);
        $id = JitSleep::zParamLong($context, $arg, $phpFn, 1, $paramName);
        $i32 = $context->getTypeFromString('int32');
        $id32 = $context->builder->trunc($id, $i32);
        $ret = $context->builder->call($context->lookupFunction($libcFn), $id32);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isTrue = $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $isTrue);

        return $ptr;
    }

    private static function ensureLibcSetId(Context $context, string $name): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureLibcPidLookup(Context $context, string $name): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureLibcSetpgid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('setpgid');
        } catch (\Throwable) {
            $ft = $context->context->functionType($i32, false, $i32, $i32);
            $fn = $context->module->addFunction('setpgid', $ft);
            $context->registerFunction('setpgid', $fn);
        }
    }

    private static function ensureLibcSetsid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('setsid');
        } catch (\Throwable) {
            $ft = $context->context->functionType($i32, false);
            $fn = $context->module->addFunction('setsid', $ft);
            $context->registerFunction('setsid', $fn);
        }
    }

    private static function ensureLibcPid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        foreach (['getpid', 'getppid'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($i32, false);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function ensureLibcEuid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('geteuid');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false);
            $fn = $context->module->addFunction('geteuid', $ft);
            $context->registerFunction('geteuid', $fn);
        }
    }

    private static function ensureLibcEgid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('getegid');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false);
            $fn = $context->module->addFunction('getegid', $ft);
            $context->registerFunction('getegid', $fn);
        }
    }

    private static function ensureLibcCtermid(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('ctermid');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i8p, false, $i8p);
            $fn = $context->module->addFunction('ctermid', $ft);
            $context->registerFunction('ctermid', $fn);
        }
    }
}
