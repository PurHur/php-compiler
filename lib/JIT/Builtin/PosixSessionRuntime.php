<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSleep;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getsid()/posix_getpgid() via PosixSessionJitHelper PHP (#12685).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc getsid/getpgid LLVM (#13040).
 * SSOT: {@see \PHPCompiler\ext\posix\VmPosixSessionPure}
 *
 * Boxed pid-or-false lives here so lib/ does not import ext/posix JitPosix (#36204).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getsid) / posix_getpgid return long|false.
 */
final class PosixSessionRuntime
{
    private const ABI_GETSID = '__posix_session__getsid';

    private const ABI_GETPGID = '__posix_session__getpgid';

    private const HELPER_PATH = '/ext/posix/PosixSessionJitHelper.php';

    private const GETSID_HELPER = 'PHPCompiler\\ext\\posix\\PosixSessionJitHelper::getsid';

    private const GETPGID_HELPER = 'PHPCompiler\\ext\\posix\\PosixSessionJitHelper::getpgid';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETSID_HELPER,
        self::GETPGID_HELPER,
    ];

    private static int $blockSerial = 0;

    public static function getsid(Context $context, JITVariable $pidArg): Value
    {
        self::ensureLinked($context);
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_getsid', 1, 'pid');
        $i64 = $context->getTypeFromString('int64');
        $pidI64 = $pid->typeOf() === $i64
            ? $pid
            : $context->builder->sext($pid, $i64);
        $raw = $context->builder->call(
            $context->lookupFunction(self::ABI_GETSID),
            $pidI64
        );

        return self::boxedPidOrFalse($context, $raw, 'posix_getsid');
    }

    public static function getpgid(Context $context, JITVariable $pidArg): Value
    {
        self::ensureLinked($context);
        $pid = JitSleep::zParamLong($context, $pidArg, 'posix_getpgid', 1, 'pid');
        $i64 = $context->getTypeFromString('int64');
        $pidI64 = $pid->typeOf() === $i64
            ? $pid
            : $context->builder->sext($pid, $i64);
        $raw = $context->builder->call(
            $context->lookupFunction(self::ABI_GETPGID),
            $pidI64
        );

        return self::boxedPidOrFalse($context, $raw, 'posix_getpgid');
    }

    /** posix_getpgrp() ≡ getpgid(0) on Linux (#19475). */
    public static function getpgrp(Context $context): Value
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call(
            $context->lookupFunction(self::ABI_GETPGID),
            $i64->constInt(0, false)
        );
        // getpgrp never returns false in php-src — always int.
        $i32 = $context->getTypeFromString('int32');
        $rawI32 = $raw->typeOf() === $i32
            ? $raw
            : $context->builder->trunc($raw, $i32);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->zExt($rawI32, $i64)
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_GETSID);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GETSID,
            'posix_session_getsid_bridge_entry',
            [$i64],
            $i64,
            self::GETSID_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12685'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GETPGID,
            'posix_session_getpgid_bridge_entry',
            [$i64],
            $i64,
            self::GETPGID_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12685'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Box libc pid return: negative → false, else long (ext/posix/posix.c).
     *
     * Formerly JitPosix::boxedPidOrFalse in ext/posix; kept in lib (#36204).
     */
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_GETSID, self::ABI_GETPGID] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after PosixSessionRuntime bridge (#12685)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
