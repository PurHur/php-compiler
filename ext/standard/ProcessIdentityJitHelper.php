<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helpers for process identity builtins (#9017, php-in-PHP).
 *
 * getmypid AOT peel lives in {@see GetmypidJitHelper} (#30623); resolveGetmypid remains
 * for VM/tests. SSOT: {@see VmProcessIdentity}, {@see VmDate::getmypid}, {@see VmProcessIdentityPure}.
 * php-src: ext/standard/basic_functions.c — getmypid, getmyuid, getmygid, get_current_user
 */
final class ProcessIdentityJitHelper
{
    public static function resolveGetmypid(): int
    {
        return VmDate::getmypid();
    }

    public static function resolveGetmyuid(): int
    {
        return VmProcessIdentity::getmyuid();
    }

    public static function resolveGetmygid(): int
    {
        return VmProcessIdentity::getmygid();
    }

    public static function resolveGetCurrentUser(string $scriptPath = ''): string
    {
        return VmProcessIdentity::getCurrentUserForScript($scriptPath);
    }
}
