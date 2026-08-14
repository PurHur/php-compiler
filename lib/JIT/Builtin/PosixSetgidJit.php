<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixSetgidKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_setgid() (#31066).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixSetgidJitHelper} via
 * {@see JitVmHelperLink} (posix_setuid #31038 shape).
 * NestedJIT leaf: module-local setgid(2) via {@see JitPosixSetgidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::setgid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setgid)
 */
final class PosixSetgidJit
{
    private const HELPER_PATH = '/ext/posix/PosixSetgidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixSetgidJitHelper::setgidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_setgid';

    private const BRIDGE_ENTRY = 'posix_setgid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_setgid() — PHP helper bridge; NestedJIT libc setgid leaf (#31066).
     *
     * @param Value $gidI64 zend long gid
     *
     * @return Value i1 — true when setgid succeeds
     */
    public static function invoke(Context $context, Value $gidI64): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixSetgidKernel::invoke($context, $gidI64);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $gidI64);
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
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31066'
        );
    }
}
