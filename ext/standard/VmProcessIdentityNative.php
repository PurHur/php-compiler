<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity for VM — pure PHP default ({@see VmProcessIdentityPure}, #9017, #12182).
 *
 * No libc getuid/getgid/getpid/getpwuid FFI on the default path — shrinks native link surface
 * for self-host/M5 (#1492).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user, getmypid
 * JIT/AOT: ProcessIdentityJit.php via ProcessIdentityJitHelper.
 */
final class VmProcessIdentityNative
{
    public static function available(): bool
    {
        return VmProcessIdentityPure::available();
    }

    public static function getuid(): ?int
    {
        return VmProcessIdentityPure::getuid();
    }

    public static function getgid(): ?int
    {
        return VmProcessIdentityPure::getgid();
    }

    public static function geteuid(): ?int
    {
        return VmProcessIdentityPure::geteuid();
    }

    public static function getpid(): ?int
    {
        return VmProcessIdentityPure::getpid();
    }

    public static function getpwuidName(int $uid): ?string
    {
        return VmProcessIdentityPure::getpwuidName($uid);
    }
}
