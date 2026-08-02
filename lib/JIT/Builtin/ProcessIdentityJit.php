<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for getmypid/getmyuid/getmygid/get_current_user (#9017, #21259, #26944, #26941).
 *
 * getmypid: libc getpid() directly (same shape as {@see \PHPCompiler\ext\standard\JitDate::time}) —
 * NestedJIT of ProcessIdentityJitHelper alone left VmDate::getmypid as an external stub that
 * returns 0 under thin AOT (#26944). php-src: ext/standard/basic_functions.c PHP_FUNCTION(getmypid).
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

    private const GETMYUID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmyuid';

    private const GETMYGID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmygid';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETMYUID,
        self::GETMYGID,
    ];

    /**
     * getmypid() — libc getpid at runtime (not NestedJIT /proc helper; #26944).
     *
     * Declared in {@see Type} libc table as i32; widen to i64 for NATIVE_LONG returns.
     */
    public static function getmypid(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getpid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
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
