<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsAccessPure;
use PHPCompiler\ext\standard\VmFsStdio;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\ext\standard\VmPhpFdStream;
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

    public static function getuid(): int
    {
        $uid = VmProcessIdentityPure::getuid();
        if (null !== $uid) {
            return $uid;
        }

        throw new \Error('posix_getuid() is not available in this compiler build');
    }

    public static function getgid(): int
    {
        $gid = VmProcessIdentityPure::getgid();
        if (null !== $gid) {
            return $gid;
        }

        throw new \Error('posix_getgid() is not available in this compiler build');
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
     * posix_initgroups() — initgroups(3) supplementary groups (#19476).
     *
     * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_initgroups)
     */
    public static function initgroups(string $username, int $groupId): bool
    {
        self::$lastError = 0;
        $ok = VmPosixIdentityWritePure::initgroups($username, $groupId);
        if (null === $ok) {
            throw new \Error('posix_initgroups() is not available in this compiler build');
        }
        if (!$ok) {
            self::$lastError = VmPosixIdentityWritePure::lastErrno();
        }

        return $ok;
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

    /**
     * posix_getlogin() — login name or false (php-src ext/posix/posix.c; #6504).
     *
     * @return string|false
     */
    public static function getlogin(): string|false
    {
        self::$lastError = 0;
        $name = VmPosixTerminalPure::getlogin();
        if (null === $name) {
            self::$lastError = PosixLibcThinAbi::available()
                ? VmPosixTerminalPure::lastErrno()
                : 0;

            return false;
        }

        return $name;
    }

    /**
     * posix_ttyname() — terminal path or false (#6504).
     *
     * @return string|false
     */
    public static function ttyname(int $fd): string|false
    {
        self::$lastError = 0;
        $name = VmPosixTerminalPure::ttyname($fd);
        if (null === $name) {
            self::$lastError = 25; // ENOTTY

            return false;
        }

        return $name;
    }

    public static function isatty(int $fd): bool
    {
        self::$lastError = 0;

        return VmPosixTerminalPure::isatty($fd);
    }

    /**
     * @return array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string}|false
     */
    public static function getpwuid(int $uid): array|false
    {
        self::$lastError = 0;
        $entry = VmPosixPasswdPure::getpwuid($uid);
        if (null === $entry) {
            self::$lastError = 0;

            return false;
        }

        return $entry;
    }

    /**
     * @return array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string}|false
     */
    public static function getpwnam(string $name): array|false
    {
        self::$lastError = 0;
        $entry = VmPosixPasswdPure::getpwnam($name);
        if (null === $entry) {
            return false;
        }

        return $entry;
    }

    /**
     * @return array{name:string,passwd:string,members:list<string>,gid:int}|false
     */
    public static function getgrgid(int $gid): array|false
    {
        self::$lastError = 0;
        $entry = VmPosixPasswdPure::getgrgid($gid);
        if (null === $entry) {
            return false;
        }

        return $entry;
    }

    /**
     * @return array{name:string,passwd:string,members:list<string>,gid:int}|false
     */
    public static function getgrnam(string $name): array|false
    {
        self::$lastError = 0;
        $entry = VmPosixPasswdPure::getgrnam($name);
        if (null === $entry) {
            return false;
        }

        return $entry;
    }

    /**
     * @param array{name:string,passwd:string,uid:int,gid:int,gecos:string,dir:string,shell:string} $entry
     */
    public static function passwdToHashTable(array $entry): HashTable
    {
        $ht = new HashTable();
        foreach (['name', 'passwd', 'uid', 'gid', 'gecos', 'dir', 'shell'] as $key) {
            $slot = new Variable();
            if ('uid' === $key || 'gid' === $key) {
                $slot->int((int) $entry[$key]);
            } else {
                $slot->string((string) $entry[$key]);
            }
            $ht->add($key, $slot);
        }

        return $ht;
    }

    /**
     * @param array{name:string,passwd:string,members:list<string>,gid:int} $entry
     */
    public static function groupToHashTable(array $entry): HashTable
    {
        $ht = new HashTable();
        foreach (['name', 'passwd'] as $key) {
            $slot = new Variable();
            $slot->string((string) $entry[$key]);
            $ht->add($key, $slot);
        }
        $membersHt = new HashTable();
        foreach ($entry['members'] as $index => $member) {
            $m = new Variable();
            $m->string((string) $member);
            $membersHt->addIndex($index, $m);
        }
        $membersSlot = new Variable();
        $membersSlot->array($membersHt);
        $ht->add('members', $membersSlot);
        $gidSlot = new Variable();
        $gidSlot->int((int) $entry['gid']);
        $ht->add('gid', $gidSlot);

        return $ht;
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
     * Returns null when the times cannot be read so callers can RETURN_FALSE
     * like php-src (`times()` failure → false, #28783).
     *
     * @return array{ticks: int, utime: int, stime: int, cutime: int, cstime: int}|null
     */
    public static function times(): ?array
    {
        return VmPosixTimesPure::times();
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

    /**
     * posix_getpgrp() — process group of calling process (#19475).
     * php-src: getpgrp() ≡ getpgid(0) on Linux.
     */
    public static function getpgrp(): int
    {
        $pgid = self::getpgid(0);
        if (false === $pgid) {
            throw new \Error('posix_getpgrp() is not available in this compiler build');
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

    public static function kill(int $pid, int $sig): bool
    {
        self::$lastError = 0;
        if ($pid === self::getpid() && \PHPCompiler\ext\pcntl\VmPcntl::hasHandler($sig)) {
            \PHPCompiler\ext\pcntl\VmPcntl::markPending($sig);

            return true;
        }
        if (!PosixLibcThinAbi::available()) {
            self::$lastError = 38;

            return false;
        }
        $rc = PosixLibcThinAbi::kill($pid, $sig);
        if (0 !== $rc) {
            self::$lastError = PosixLibcThinAbi::readErrno();
        }

        return 0 === $rc;
    }

    /**
     * posix_sysconf() — sysconf(3) (php-src ext/posix/posix.c; #20509).
     */
    public static function sysconf(int $confId): int
    {
        self::$lastError = 0;
        if (!PosixLibcThinAbi::available()) {
            throw new \Error('posix_sysconf() is not available in this compiler build');
        }

        return PosixLibcThinAbi::sysconf($confId);
    }

    /**
     * posix_pathconf() — pathconf(3) (php-src ext/posix/posix.c; #20509).
     *
     * @return int|false
     */
    public static function pathconf(string $path, int $name): int|false
    {
        self::$lastError = 0;
        if (!PosixLibcThinAbi::available()) {
            throw new \Error('posix_pathconf() is not available in this compiler build');
        }
        [$ret, $errno] = PosixLibcThinAbi::pathconf($path, $name);
        if ($ret < 0 && 0 !== $errno) {
            self::$lastError = $errno;

            return false;
        }

        return $ret;
    }

    /**
     * posix_fpathconf() — fpathconf(3) (php-src ext/posix/posix.c; #20509).
     *
     * @return int|false
     */
    public static function fpathconf(int $fd, int $name): int|false
    {
        self::$lastError = 0;
        if (!PosixLibcThinAbi::available()) {
            throw new \Error('posix_fpathconf() is not available in this compiler build');
        }
        [$ret, $errno] = PosixLibcThinAbi::fpathconf($fd, $name);
        if ($ret < 0 && 0 !== $errno) {
            self::$lastError = $errno;

            return false;
        }

        return $ret;
    }

    /**
     * posix_eaccess() — eaccess(3) effective-UID probe (php-src ext/posix/posix.c; #20509).
     */
    public static function eaccess(string $path, int $mode): bool
    {
        self::$lastError = 0;
        if (!PosixLibcThinAbi::available()) {
            throw new \Error('posix_eaccess() is not available in this compiler build');
        }
        if (str_contains($path, "\0")) {
            self::$lastError = 2;

            return false;
        }
        [$ret, $errno] = PosixLibcThinAbi::eaccess($path, $mode);
        if (0 !== $ret) {
            self::$lastError = 0 !== $errno ? $errno : 13;

            return false;
        }

        return true;
    }

    /**
     * Resolve posix_fpathconf() $file_descriptor (int|resource) like php-src (#20509).
     *
     * @return int|null native fd, or null when a stream resource cannot yield an fd (return false)
     *
     * @throws \TypeError
     */
    public static function resolveFileDescriptorArg(
        Variable $var,
        string $function,
        int $argIndex
    ): ?int {
        $v = $var->resolveIndirect();
        if ($v->isStreamResource()) {
            $handle = ResourceSupport::resolveHandle($v);
            if (null === $handle) {
                return null;
            }

            return self::nativeFdForStreamHandle($handle);
        }
        if (\PHPCompiler\ext\standard\is_resource_::isResource($v)) {
            // Non-stream resources cannot supply an fd (php_posix_stream_get_fd FAILURE).
            return null;
        }
        self::rejectEnumCaseIntArg($v, $function, $argIndex, 'file_descriptor');
        if (Variable::TYPE_INTEGER !== $v->type
            && Variable::TYPE_FLOAT !== $v->type
            && Variable::TYPE_BOOLEAN !== $v->type
            && Variable::TYPE_NULL !== $v->type
            && Variable::TYPE_STRING !== $v->type
        ) {
            $given = EnumCaseSupport::typeNameForVariable($v);
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #%d ($file_descriptor) must be of type int|resource, %s given',
                    $function,
                    $argIndex + 1,
                    $given
                )
            );
        }

        return $v->toInt();
    }

    /**
     * Map a VM stream handle to a native fd for fpathconf (php-src php_posix_stream_get_fd).
     */
    public static function nativeFdForStreamHandle(int $handle): ?int
    {
        $fd = VmPhpFdStream::fdForHandle($handle);
        if (null !== $fd) {
            return $fd;
        }
        $fd = VmFs::socketFdForHandle($handle);
        if (null !== $fd) {
            return $fd;
        }
        $uri = VmFs::handleUri($handle);
        $stdio = VmFsStdio::stdioFdForUri($uri);
        if (null !== $stdio) {
            return $stdio;
        }

        return null;
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
