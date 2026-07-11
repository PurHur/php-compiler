<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setuid/setgid/seteuid/setegid via thin libc ABI (#12733).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setuid), posix_setgid, …
 */
final class VmPosixIdentityWritePure
{
    public static function available(): bool
    {
        return PosixLibcThinAbi::available();
    }

    public static function setuid(int $uid): ?bool
    {
        return self::setId('setuid', $uid);
    }

    public static function setgid(int $gid): ?bool
    {
        return self::setId('setgid', $gid);
    }

    public static function seteuid(int $uid): ?bool
    {
        return self::setId('seteuid', $uid);
    }

    public static function setegid(int $gid): ?bool
    {
        return self::setId('setegid', $gid);
    }

    public static function lastErrno(): int
    {
        return PosixLibcThinAbi::readErrno();
    }

    private static function setId(string $fn, int $id): ?bool
    {
        if (!self::available()) {
            return null;
        }

        $ret = match ($fn) {
            'setuid' => PosixLibcThinAbi::setuid($id),
            'setgid' => PosixLibcThinAbi::setgid($id),
            'seteuid' => PosixLibcThinAbi::seteuid($id),
            'setegid' => PosixLibcThinAbi::setegid($id),
            default => -1,
        };

        return 0 === $ret;
    }
}
