<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixSeteuidKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_seteuid() (#31066).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixSeteuidJitHelper} via
 * {@see JitVmHelperLink} (posix_setuid #31038 shape).
 * NestedJIT leaf: module-local seteuid(2) via {@see JitPosixSeteuidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::seteuid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_seteuid)
 */
final class PosixSeteuidJit
{
    private const HELPER_PATH = '/ext/posix/PosixSeteuidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixSeteuidJitHelper::seteuidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_seteuid';

    private const BRIDGE_ENTRY = 'posix_seteuid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_seteuid() — PHP helper bridge; NestedJIT libc seteuid leaf (#31066).
     *
     * @param Value $uidI64 zend long uid
     *
     * @return Value i1 — true when seteuid succeeds
     */
    public static function invoke(Context $context, Value $uidI64): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixSeteuidKernel::invoke($context, $uidI64);
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
