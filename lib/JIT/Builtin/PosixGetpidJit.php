<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitGetmypidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getpid() (#30696).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixGetpidJitHelper} via
 * {@see JitVmHelperLink} (getmypid #30623 / proc_nice #30615 shape).
 * NestedJIT leaf: module-local getpid(2) via {@see JitGetmypidKernel} (shared with
 * getmypid — avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::getpid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getpid)
 */
final class PosixGetpidJit
{
    private const HELPER_PATH = '/ext/posix/PosixGetpidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixGetpidJitHelper::getpidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_getpid';

    private const BRIDGE_ENTRY = 'posix_getpid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_getpid() — PHP helper bridge; NestedJIT libc getpid leaf (#30696).
     *
     * @return Value int64 process id
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitGetmypidKernel::invoke($context);
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
            '#30696'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
