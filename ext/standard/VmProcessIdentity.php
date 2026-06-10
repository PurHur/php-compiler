<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity builtins (issue #6119, native libc #7891).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user.
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
        if (\function_exists('posix_getuid')) {
            return (int) \posix_getuid();
        }
        if (\function_exists('getuid')) {
            return (int) \getuid();
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
        if (\function_exists('posix_getgid')) {
            return (int) \posix_getgid();
        }
        if (\function_exists('getgid')) {
            return (int) \getgid();
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
        $euid = null;
        if (\function_exists('posix_geteuid')) {
            $euid = (int) \posix_geteuid();
        } elseif (\function_exists('geteuid')) {
            $euid = (int) \geteuid();
        }
        if (null !== $euid && \function_exists('posix_getpwuid')) {
            $pw = @\posix_getpwuid($euid);
            if (\is_array($pw) && isset($pw['name']) && '' !== $pw['name']) {
                return (string) $pw['name'];
            }
        }
        if (null !== $euid && \function_exists('getpwuid')) {
            $pw = @\getpwuid($euid);
            if (\is_object($pw) && isset($pw->pw_name) && '' !== $pw->pw_name) {
                return (string) $pw->pw_name;
            }
        }

        return 'Unknown';
    }
}
