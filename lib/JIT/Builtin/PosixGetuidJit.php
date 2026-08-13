<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixGetuidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getuid() (#30744).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixGetuidJitHelper} via
 * {@see JitVmHelperLink} (posix_getppid #30728 / posix_getpid #30696 shape).
 * NestedJIT leaf: module-local getuid(2) via {@see JitPosixGetuidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::getuid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getuid)
 */
final class PosixGetuidJit
{
    private const HELPER_PATH = '/ext/posix/PosixGetuidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixGetuidJitHelper::getuidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_getuid';

    private const BRIDGE_ENTRY = 'posix_getuid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_getuid() — PHP helper bridge; NestedJIT libc getuid leaf (#30744).
     *
     * @return Value int64 real user id
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixGetuidKernel::invoke($context);
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
            '#30744'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
