<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity builtins (issue #6119, pure /proc #9017, libc FFI fallback #7891).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user.
 * No host \\posix_* / \\get*() delegation — VmProcessIdentityPure / VmProcessIdentityNative only.
 */
final class VmProcessIdentity
{
    public static function getmyuid(): int
    {
        $uid = VmProcessIdentityNative::getuid();
        if (null !== $uid) {
            return $uid;
        }

        throw new \LogicException('getmyuid() requires POSIX support in this compiler build');
    }

    public static function getmygid(): int
    {
        $gid = VmProcessIdentityNative::getgid();
        if (null !== $gid) {
            return $gid;
        }

        throw new \LogicException('getmygid() requires POSIX support in this compiler build');
    }

    public static function getCurrentUser(): string
    {
        $euid = VmProcessIdentityNative::geteuid();
        if (null !== $euid) {
            $name = VmProcessIdentityNative::getpwuidName($euid);
            if (null !== $name) {
                return $name;
            }
        }

        return 'Unknown';
    }
}
