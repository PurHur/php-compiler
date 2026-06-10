<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity builtins (issue #6119, native libc #7891).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user.
 * No host \\posix_* / \\get*() delegation — libc FFI only (pairs #7287 getrusage shrink).
 */
final class VmProcessIdentity
{
    public static function getmyuid(): int
    {
        if (VmProcessIdentityNative::available()) {
            $uid = VmProcessIdentityNative::getuid();
            if (null !== $uid) {
                return $uid;
            }
        }

        throw new \LogicException('getmyuid() requires POSIX support in this compiler build');
    }

    public static function getmygid(): int
    {
        if (VmProcessIdentityNative::available()) {
            $gid = VmProcessIdentityNative::getgid();
            if (null !== $gid) {
                return $gid;
            }
        }

        throw new \LogicException('getmygid() requires POSIX support in this compiler build');
    }

    public static function getCurrentUser(): string
    {
        if (VmProcessIdentityNative::available()) {
            $euid = VmProcessIdentityNative::geteuid();
            if (null !== $euid) {
                $name = VmProcessIdentityNative::getpwuidName($euid);
                if (null !== $name) {
                    return $name;
                }
            }
        }

        return 'Unknown';
    }
}
