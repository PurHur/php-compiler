<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixSetuidKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_setuid() (#31038).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixSetuidJitHelper} via
 * {@see JitVmHelperLink} (posix_getegid #30986 / proc_nice #30615 shape).
 * NestedJIT leaf: module-local setuid(2) via {@see JitPosixSetuidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::setuid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setuid)
 */
final class PosixSetuidJit
{
    private const HELPER_PATH = '/ext/posix/PosixSetuidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixSetuidJitHelper::setuidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_setuid';

    private const BRIDGE_ENTRY = 'posix_setuid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_setuid() — PHP helper bridge; NestedJIT libc setuid leaf (#31038).
     *
     * @param Value $uidI64 zend long uid
     *
     * @return Value i1 — true when setuid succeeds
     */
    public static function invoke(Context $context, Value $uidI64): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixSetuidKernel::invoke($context, $uidI64);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $uidI64);
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
        // ABI stays i1 for posix_setuid() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
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
            '#31038'
        );
    }
}
