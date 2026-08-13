<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixGetppidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getppid() (#30728).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixGetppidJitHelper} via
 * {@see JitVmHelperLink} (posix_getpid #30696 / getmypid #30623 shape).
 * NestedJIT leaf: module-local getppid(2) via {@see JitPosixGetppidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::getppid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getppid)
 */
final class PosixGetppidJit
{
    private const HELPER_PATH = '/ext/posix/PosixGetppidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixGetppidJitHelper::getppidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_getppid';

    private const BRIDGE_ENTRY = 'posix_getppid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_getppid() — PHP helper bridge; NestedJIT libc getppid leaf (#30728).
     *
     * @return Value int64 parent process id
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixGetppidKernel::invoke($context);
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
            '#30728'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
