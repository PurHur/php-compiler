<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSleep;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getsid()/posix_getpgid() via PosixSessionJitHelper PHP (#12685).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc getsid/getpgid LLVM (#13040).
 * SSOT: {@see \PHPCompiler\ext\posix\VmPosixSessionPure}
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

        return \PHPCompiler\ext\posix\JitPosix::boxedPidOrFalse($context, $raw, 'posix_getsid');
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

        return \PHPCompiler\ext\posix\JitPosix::boxedPidOrFalse($context, $raw, 'posix_getpgid');
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
