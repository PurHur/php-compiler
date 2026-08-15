<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixSetpgidKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_setpgid() (#31235).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixSetpgidJitHelper} via
 * {@see JitVmHelperLink} (posix_setuid #31038 / posix_setgid #31066 shape).
 * NestedJIT leaf: module-local setpgid(2) via {@see JitPosixSetpgidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::setpgid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setpgid)
 */
final class PosixSetpgidJit
{
    private const HELPER_PATH = '/ext/posix/PosixSetpgidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixSetpgidJitHelper::setpgidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_setpgid';

    private const BRIDGE_ENTRY = 'posix_setpgid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_setpgid() — PHP helper bridge; NestedJIT libc setpgid leaf (#31235).
     *
     * @param Value $pidI64  zend long process_id
     * @param Value $pgidI64 zend long process_group_id
     *
     * @return Value i1 — true when setpgid succeeds
     */
    public static function invoke(Context $context, Value $pidI64, Value $pgidI64): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixSetpgidKernel::invoke($context, $pidI64, $pgidI64);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $pidI64, $pgidI64);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        // ABI stays i1 for posix_setpgid() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64, $i64],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31235'
        );
    }
}
