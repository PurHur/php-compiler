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
 * JIT/AOT link for getmypid/getmyuid/getmygid/get_current_user (#9017, #21259, #26944, #26941, #30623).
 *
 * getmypid: {@see GetmypidJitHelper} via {@see JitVmHelperLink} (time #30332 /
 * proc_nice #30615 shape). NestedJIT leaf: module-local getpid(2) via
 * {@see JitGetmypidKernel} (avoids re-entering the helper / former stub-0 #26944).
 *
 * get_current_user: libc geteuid+getpwuid via {@see \PHPCompiler\ext\standard\JitGetCurrentUser}
 * (NestedJIT SEGV under thin AOT — #26941).
 *
 * getmyuid/getmygid: Nested helper compile via {@see JitVmHelperLink::ensureCompiled}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmProcessIdentity}, {@see \PHPCompiler\ext\standard\VmDate::getmypid} (VM).
 * php-src: ext/standard/basic_functions.c — getmypid, getmyuid, getmygid, get_current_user
 */
final class ProcessIdentityJit
{
    private const HELPER_PATH = '/ext/standard/ProcessIdentityJitHelper.php';

    private const GETMYPID_HELPER_PATH = '/ext/standard/GetmypidJitHelper.php';

    private const GETMYPID_HELPER = 'PHPCompiler\\ext\\standard\\GetmypidJitHelper::getmypidArgv';

    private const GETMYUID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmyuid';

    private const GETMYGID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmygid';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETMYUID,
        self::GETMYGID,
    ];

    /** @var list<string> */
    private const GETMYPID_COMPILED_HELPERS = [
        self::GETMYPID_HELPER,
    ];

    private const GETMYPID_ABI = '__compiler_getmypid';

    private const GETMYPID_BRIDGE_ENTRY = 'getmypid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implementGetmypid($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * getmypid() — PHP helper bridge; NestedJIT libc getpid leaf (#30623).
     *
     * @return Value int64 process id
     */
    public static function getmypid(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitGetmypidKernel::invoke($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::GETMYPID_ABI));
    }

    public static function getmyuid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYUID);
    }

    public static function getmygid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYGID);
    }

    public static function getCurrentUser(Context $context): Value
    {
        return \PHPCompiler\ext\standard\JitGetCurrentUser::invoke($context);
    }

    private static function implementGetmypid(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::GETMYPID_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::GETMYPID_BRIDGE_ENTRY)) {
            $context->registerFunction(self::GETMYPID_ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::GETMYPID_ABI,
            self::GETMYPID_BRIDGE_ENTRY,
            [],
            $i64,
            self::GETMYPID_HELPER,
            self::GETMYPID_HELPER_PATH,
            self::GETMYPID_COMPILED_HELPERS,
            '#30623'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function callIntHelper(Context $context, string $logical): Value
    {
        self::ensureCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, $logical, '#21259');
        $raw = $context->builder->call($helperFn);
        $i64 = $context->getTypeFromString('int64');

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21259'
        );
    }
}
