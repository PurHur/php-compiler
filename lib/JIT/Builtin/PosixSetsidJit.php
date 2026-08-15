<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixSetsidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_setsid() (#31235).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixSetsidJitHelper} via
 * {@see JitVmHelperLink} (posix_getpid #30696 / posix_setuid #31038 shape).
 * NestedJIT leaf: module-local setsid(2) via {@see JitPosixSetsidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::setsid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setsid)
 */
final class PosixSetsidJit
{
    private const HELPER_PATH = '/ext/posix/PosixSetsidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixSetsidJitHelper::setsidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_setsid';

    private const BRIDGE_ENTRY = 'posix_setsid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_setsid() — PHP helper bridge; NestedJIT libc setsid leaf (#31235).
     *
     * @return Value int64 session id (negative on failure — peer VmPosix::setsid)
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixSetsidKernel::invoke($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI));
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31235'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
