<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmFsAccessPure;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\ext\standard\VmProcessIdentityPure;
use PHPCompiler\ext\standard\VmStatCache;
use PHPCompiler\ext\standard\VmUnamePure;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for posix builtins (php-src ext/posix/posix.c; #7271, #7376, #7177).
 *
 * Read paths use procfs SSOT; write syscalls delegate to *Pure helpers + {@see PosixLibcThinAbi}.
 */
final class VmPosix
{
    /** Last errno recorded by posix builtins (php-src posix_errno global). */
    private static int $lastError = 0;

    public static function getpid(): int
    {
        return VmDate::getmypid();
    }

    public static function getppid(): int
    {
        $ppid = VmProcessIdentityPure::getppid();
        if (null !== $ppid) {
            return $ppid;
        }

        throw new \Error('posix_getppid() is not available in this compiler build');
    }

    public static function geteuid(): int
    {
        $euid = VmProcessIdentityPure::geteuid();
        if (null !== $euid) {
            return $euid;
        }

        throw new \Error('posix_geteuid() is not available in this compiler build');
    }

    public static function getegid(): int
    {
        $egid = VmProcessIdentityPure::getegid();
        if (null !== $egid) {
            return $egid;
        }

        throw new \Error('posix_getegid() is not available in this compiler build');
    }

    /**
     * @return list<int>|false
     */
    public static function getgroups(): array|false
    {
        self::$lastError = 0;
        $groups = VmProcessIdentityPure::getgroups();
        if (null !== $groups) {
            return $groups;
        }

        throw new \Error('posix_getgroups() is not available in this compiler build');
    }

    /**
     * @return array<string, string>|false
     */
    public static function uname(): array|false
    {
        self::$lastError = 0;
        if (VmUnamePure::available()) {
            return VmUnamePure::utsname();
        }

        throw new \Error('posix_uname() is not available in this compiler build');
    }

    public static function strerror(int $errno): string
    {
        return VmPosixStrerrorPure::message($errno);
    }

    public static function access(string $path, int $mode): bool
    {
        self::$lastError = 0;
        if (str_contains($path, "\0")) {
            self::$lastError = 2;

            return false;
        }

        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            self::$lastError = 2;

            return false;
        }

        if (0 === $mode) {
            return true;
        }

        if (VmFsAccessPure::access($path, $mode)) {
            return true;
        }

        self::$lastError = 13;

        return false;
    }

    public static function mknod(string $path, int $mode, int $major = 0, int $minor = 0): bool
    {
        self::$lastError = 0;
        $ok = VmPosixMknodPure::mknod($path, $mode, $major, $minor);
        if (null === $ok) {
            throw new \Error('posix_mknod() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixMknodPure::lastErrno();
        }

        return $ok;
    }

    public static function mkfifo(string $path, int $mode): bool
    {
        self::$lastError = 0;
        $ok = VmPosixMknodPure::mkfifo($path, $mode);
        if (null === $ok) {
            throw new \Error('posix_mkfifo() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixMknodPure::lastErrno();
        }

        return $ok;
    }

    public static function setuid(int $uid): bool
    {
        return self::setId('setuid', $uid);
    }

    public static function setgid(int $gid): bool
    {
        return self::setId('setgid', $gid);
    }

    public static function seteuid(int $uid): bool
    {
        return self::setId('seteuid', $uid);
    }

    public static function setegid(int $gid): bool
    {
        return self::setId('setegid', $gid);
    }

    /**
     * @throws \TypeError php-src Z_PARAM_LONG rejects enum cases (#7372, #7373, #7374)
     */
    public static function rejectEnumCaseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        $given = EnumCaseSupport::typeNameForVariable($var);
        throw new \TypeError(
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $given
            )
        );
    }

    /**
     * @throws \TypeError
     */
    public static function coerceIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        self::rejectEnumCaseIntArg($var, $function, $argIndex, $paramName);

        return $var->resolveIndirect()->toInt();
    }

    /**
     * @return string|false
     */
    public static function getcwd(): string|false
    {
        self::$lastError = 0;
        $cwd = VmGetcwdNative::resolve();
        if (false === $cwd) {
            self::$lastError = PosixLibcThinAbi::available()
                ? PosixLibcThinAbi::readErrno()
                : 2;

            return false;
        }

        return $cwd;
    }

    public static function ctermid(): string
    {
        return VmPosixCtermidPure::path();
    }

    public static function getLastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $errno): void
    {
        self::$lastError = $errno;
    }

    public static function ffiAvailable(): bool
    {
        return PosixLibcThinAbi::available();
    }

    /**
     * Resolve login name to uid via /etc/passwd (#7917, #12454; JIT StringFsDirJit parity).
     */
    public static function uidForName(string $name): ?int
    {
        $uid = VmProcessIdentityPure::uidForName($name);
        if (null !== $uid) {
            return $uid;
        }

        return null;
    }

    /**
     * Resolve group name to gid via /etc/group (#7917; JIT StringFsDirJit parity).
     */
    public static function gidForName(string $name): ?int
    {
        $gid = VmProcessIdentityPure::gidForName($name);
        if (null !== $gid) {
            return $gid;
        }

        return null;
    }

    /**
     * posix_times() — process times (php-src ext/posix/posix.c; #7173).
     *
     * @return array{ticks: int, utime: int, stime: int, cutime: int, cstime: int}
     */
    public static function times(): array
    {
        $pure = VmPosixTimesPure::times();
        if (null !== $pure) {
            return $pure;
        }

        throw new \Error('posix_times() is not available in this compiler build');
    }

    /**
     * @param array{ticks: int, utime: int, stime: int, cutime: int, cstime: int} $raw
     */
    public static function timesToHashTable(array $raw): HashTable
    {
        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            $slot->int((int) $value);
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    /**
     * posix_getrlimit() — resource limits map (php-src ext/posix/posix.c; #7173).
     *
     * @return array<string, int|string>
     */
    public static function getrlimit(): array
    {
        $pure = VmPosixRlimitPure::getrlimit();
        if (null !== $pure) {
            return $pure;
        }

        throw new \Error('posix_getrlimit() is not available in this compiler build');
    }

    public static function setrlimit(int $resource, int $softLimit, int $hardLimit): bool
    {
        self::$lastError = 0;
        $ok = VmPosixRlimitPure::setrlimit($resource, $softLimit, $hardLimit);
        if (null === $ok) {
            throw new \Error('posix_setrlimit() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixRlimitPure::lastErrno();
        }

        return $ok;
    }

    public static function setsid(): int
    {
        self::$lastError = 0;
        $sid = VmPosixSessionPure::setsid();
        if (null === $sid) {
            throw new \Error('posix_setsid() is not available in this compiler build');
        }
        if ($sid < 0) {
            self::$lastError = VmPosixSessionPure::lastErrno();
        }

        return $sid;
    }

    public static function getsid(int $pid): int|false
    {
        self::$lastError = 0;
        $sid = VmPosixSessionPure::getsid($pid);
        if (null === $sid) {
            self::$lastError = 3;

            return false;
        }

        return $sid;
    }

    public static function getpgid(int $pid): int|false
    {
        self::$lastError = 0;
        $pgid = VmPosixSessionPure::getpgid($pid);
        if (null === $pgid) {
            self::$lastError = 3;

            return false;
        }

        return $pgid;
    }

    public static function setpgid(int $pid, int $pgid): bool
    {
        self::$lastError = 0;
        $ok = VmPosixSessionPure::setpgid($pid, $pgid);
        if (null === $ok) {
            throw new \Error('posix_setpgid() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixSessionPure::lastErrno();
        }

        return $ok;
    }

    private static function setId(string $fn, int $id): bool
    {
        self::$lastError = 0;
        $ok = match ($fn) {
            'setuid' => VmPosixIdentityWritePure::setuid($id),
            'setgid' => VmPosixIdentityWritePure::setgid($id),
            'seteuid' => VmPosixIdentityWritePure::seteuid($id),
            'setegid' => VmPosixIdentityWritePure::setegid($id),
            default => null,
        };
        if (null === $ok) {
            throw new \Error('posix_'.$fn.'() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixIdentityWritePure::lastErrno();
        }

        return $ok;
    }
}
