<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for posix builtins (php-src ext/posix/posix.c; #7271, #7376, #7177).
 *
 * Libc via FFI when available; no host \\posix_* delegation (M5 bootstrap path).
 */
final class VmPosix
{
    private static ?\FFI $ffi = null;

    /** Last errno recorded by posix builtins (php-src posix_errno global). */
    private static int $lastError = 0;

    public static function getpid(): int
    {
        return VmDate::getmypid();
    }

    public static function getppid(): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->getppid();
        }

        throw new \Error('posix_getppid() is not available in this compiler build');
    }

    public static function geteuid(): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->geteuid();
        }

        throw new \Error('posix_geteuid() is not available in this compiler build');
    }

    public static function getegid(): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->getegid();
        }

        throw new \Error('posix_getegid() is not available in this compiler build');
    }

    /**
     * @return list<int>|false
     */
    public static function getgroups(): array|false
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_getgroups() is not available in this compiler build');
        }

        $count = (int) $ffi->getgroups(0, null);
        if ($count < 0) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }
        if (0 === $count) {
            return [];
        }

        $list = $ffi->new('gid_t['.$count.']');
        $ngroups = (int) $ffi->getgroups($count, \FFI::addr($list[0]));
        if ($ngroups < 0) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        $groups = [];
        for ($i = 0; $i < $ngroups; ++$i) {
            $groups[] = (int) $list[$i];
        }

        return $groups;
    }

    /**
     * @return array<string, string>|false
     */
    public static function uname(): array|false
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_uname() is not available in this compiler build');
        }

        $buf = $ffi->new('struct utsname');
        if (0 !== (int) $ffi->uname(\FFI::addr($buf))) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return [
            'sysname' => \FFI::string($buf->sysname),
            'nodename' => \FFI::string($buf->nodename),
            'release' => \FFI::string($buf->release),
            'version' => \FFI::string($buf->version),
            'machine' => \FFI::string($buf->machine),
            'domainname' => \FFI::string($buf->domainname),
        ];
    }

    public static function strerror(int $errno): string
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $msgPtr = $ffi->strerror($errno);
            if (null !== $msgPtr) {
                $msg = \FFI::string($msgPtr);
                if ('' !== $msg) {
                    return $msg;
                }
            }
        }

        return 'Unknown error '.$errno;
    }

    public static function access(string $path, int $mode): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_access() is not available in this compiler build');
        }
        if (0 !== (int) $ffi->access($path, $mode)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    public static function mknod(string $path, int $mode, int $major = 0, int $minor = 0): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_mknod() is not available in this compiler build');
        }
        $dev = self::makeDev($mode, $major, $minor);
        if (0 !== (int) $ffi->mknod($path, $mode, $dev)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    public static function mkfifo(string $path, int $mode): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_mkfifo() is not available in this compiler build');
        }
        $fifoMode = $mode | PosixConstants::S_IFIFO;
        if (0 !== (int) $ffi->mkfifo($path, $fifoMode)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
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
            $ffi = self::ffi();
            if (null !== $ffi) {
                self::$lastError = self::readErrno($ffi);
            }

            return false;
        }

        return $cwd;
    }

    public static function ctermid(): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return '';
        }
        $ptr = $ffi->ctermid(null);
        if (null === $ptr) {
            return '';
        }

        return \FFI::string($ptr);
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
        return null !== self::ffi();
    }

    /**
     * Resolve login name to uid via libc getpwnam(3) (#7917; JIT StringFsDirJit parity).
     */
    public static function uidForName(string $name): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $pw = $ffi->getpwnam($name);
        if (null === $pw) {
            return null;
        }

        return (int) $pw->pw_uid;
    }

    /**
     * Resolve group name to gid via libc getgrnam(3) (#7917; JIT StringFsDirJit parity).
     */
    public static function gidForName(string $name): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $gr = $ffi->getgrnam($name);
        if (null === $gr) {
            return null;
        }

        return (int) $gr->gr_gid;
    }

    /**
     * posix_times() — process times (php-src ext/posix/posix.c; #7173).
     *
     * @return array{ticks: int, utime: int, stime: int, cutime: int, cstime: int}
     */
    public static function times(): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_times() is not available in this compiler build');
        }

        $tms = $ffi->new('struct tms');
        $ticks = (int) $ffi->times(\FFI::addr($tms));
        if (-1 === $ticks) {
            self::$lastError = self::readErrno($ffi);

            throw new \Error('posix_times() failed');
        }

        return [
            'ticks' => $ticks,
            'utime' => (int) $tms->tms_utime,
            'stime' => (int) $tms->tms_stime,
            'cutime' => (int) $tms->tms_cutime,
            'cstime' => (int) $tms->tms_cstime,
        ];
    }

    /**
     * posix_getrlimit() — resource limits map (php-src ext/posix/posix.c; #7173).
     *
     * @return array<string, int|string>
     */
    public static function getrlimit(): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_getrlimit() is not available in this compiler build');
        }

        $out = [];
        foreach (self::rlimitResources() as $resource => $name) {
            $rlim = $ffi->new('struct rlimit');
            if (0 !== (int) $ffi->getrlimit($resource, \FFI::addr($rlim))) {
                self::$lastError = self::readErrno($ffi);

                throw new \Error('posix_getrlimit() failed');
            }
            $out['soft '.$name] = self::formatRlimitValue((int) $rlim->rlim_cur);
            $out['hard '.$name] = self::formatRlimitValue((int) $rlim->rlim_max);
        }

        return $out;
    }

    public static function setrlimit(int $resource, int $softLimit, int $hardLimit): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_setrlimit() is not available in this compiler build');
        }

        $rlim = $ffi->new('struct rlimit');
        $rlim->rlim_cur = self::parseRlimitInput($softLimit);
        $rlim->rlim_max = self::parseRlimitInput($hardLimit);
        if (0 !== (int) $ffi->setrlimit($resource, \FFI::addr($rlim))) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    public static function setsid(): int
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_setsid() is not available in this compiler build');
        }

        $sid = (int) $ffi->setsid();
        if ($sid < 0) {
            self::$lastError = self::readErrno($ffi);
        }

        return $sid;
    }

    public static function getsid(int $pid): int|false
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_getsid() is not available in this compiler build');
        }

        $sid = (int) $ffi->getsid($pid);
        if ($sid < 0) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return $sid;
    }

    public static function getpgid(int $pid): int|false
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_getpgid() is not available in this compiler build');
        }

        $pgid = (int) $ffi->getpgid($pid);
        if ($pgid < 0) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return $pgid;
    }

    public static function setpgid(int $pid, int $pgid): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_setpgid() is not available in this compiler build');
        }
        if (0 !== (int) $ffi->setpgid($pid, $pgid)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    private static function setId(string $fn, int $id): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_'.$fn.'() is not available in this compiler build');
        }
        if (0 !== (int) $ffi->$fn($id)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    private static function readErrno(\FFI $ffi): int
    {
        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    private static function makeDev(int $mode, int $major, int $minor): int
    {
        $type = $mode & PosixConstants::S_IFMT;
        if ($type !== PosixConstants::S_IFCHR && $type !== PosixConstants::S_IFBLK) {
            return 0;
        }

        return (($major & 0x00000fff) << 8)
            | ($minor & 0x000000ff)
            | (($major & 0xfffff000) << 32)
            | (($minor & 0xffffff00) << 12);
    }

    /**
     * @return array<int, string>
     */
    private static function rlimitResources(): array
    {
        return [
            PosixConstants::RLIMIT_CORE => 'core',
            PosixConstants::RLIMIT_DATA => 'data',
            PosixConstants::RLIMIT_STACK => 'stack',
            PosixConstants::RLIMIT_AS => 'totalmem',
            PosixConstants::RLIMIT_RSS => 'rss',
            PosixConstants::RLIMIT_NPROC => 'maxproc',
            PosixConstants::RLIMIT_MEMLOCK => 'memlock',
            PosixConstants::RLIMIT_CPU => 'cpu',
            PosixConstants::RLIMIT_FSIZE => 'filesize',
            PosixConstants::RLIMIT_NOFILE => 'openfiles',
        ];
    }

    /**
     * @return int|string php-src prints "unlimited" for RLIM_INFINITY
     */
    private static function formatRlimitValue(int $raw): int|string
    {
        if ($raw < 0 || $raw > \PHP_INT_MAX) {
            return 'unlimited';
        }

        return $raw;
    }

    private static function parseRlimitInput(int $value): int
    {
        if (PosixConstants::RLIMIT_INFINITY === $value) {
            return -1;
        }

        return $value;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;
typedef unsigned int mode_t;
typedef unsigned long long dev_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
pid_t getppid(void);
uid_t geteuid(void);
gid_t getegid(void);
int getgroups(int size, gid_t *list);
struct utsname {
    char sysname[65];
    char nodename[65];
    char release[65];
    char version[65];
    char machine[65];
    char domainname[65];
};
int uname(struct utsname *buf);
char *strerror(int errnum);
int access(const char *pathname, int mode);
int mknod(const char *pathname, mode_t mode, dev_t dev);
int mkfifo(const char *pathname, mode_t mode);
int setuid(uid_t uid);
int setgid(gid_t gid);
int seteuid(uid_t uid);
int setegid(gid_t gid);
struct passwd {
    char *pw_name;
    char *pw_passwd;
    uid_t pw_uid;
    gid_t pw_gid;
    char *pw_gecos;
    char *pw_dir;
    char *pw_shell;
};
struct group {
    char *gr_name;
    char *gr_passwd;
    gid_t gr_gid;
    char **gr_mem;
};
struct passwd *getpwnam(const char *name);
struct group *getgrnam(const char *name);
int *__errno_location(void);
char *ctermid(char *s);
typedef long clock_t;
struct tms {
    clock_t tms_utime;
    clock_t tms_stime;
    clock_t tms_cutime;
    clock_t tms_cstime;
};
clock_t times(struct tms *buf);
typedef unsigned long rlim_t;
struct rlimit {
    rlim_t rlim_cur;
    rlim_t rlim_max;
};
int getrlimit(int resource, struct rlimit *rlim);
int setrlimit(int resource, const struct rlimit *rlim);
pid_t setsid(void);
pid_t getsid(pid_t pid);
pid_t getpgid(pid_t pid);
int setpgid(pid_t pid, pid_t pgid);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
