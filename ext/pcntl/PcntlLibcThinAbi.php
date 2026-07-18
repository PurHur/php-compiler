<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

/** Thin libc ABI for pcntl signal registration (php-src ext/pcntl/pcntl.c; issue #6680). */
final class PcntlLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    /** @var \Closure|null */
    private static $signalCallback = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function supportsNativeDispatch(): bool
    {
        return self::available() && \method_exists(\FFI::class, 'callback');
    }

    public static function installHandler(int $signo): bool
    {
        if (!self::supportsNativeDispatch()) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (null === self::$signalCallback) {
            self::$signalCallback = static function (int $signo): void {
                VmPcntl::markPending($signo);
            };
        }
        $handler = \FFI::callback('void(int)', self::$signalCallback);
        $ffi->signal($signo, $handler);

        return true;
    }

    public static function restoreDefault(int $signo): bool
    {
        if (!self::supportsNativeDispatch()) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $ffi->signal($signo, $ffi->cast('sighandler_t', 0));

        return true;
    }

    public static function installDisposition(int $signo, int $disposition): bool
    {
        if (!self::supportsNativeDispatch()) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $handlerVal = PcntlConstants::SIG_IGN === $disposition ? 1 : 0;
        $ffi->signal($signo, $ffi->cast('sighandler_t', $handlerVal));

        return true;
    }

    public static function sigprocmaskAvailable(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param list<int> $signals
     * @param list<int> $old
     */
    public static function sigprocmask(int $mode, array $signals, array &$old): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $set = $ffi->new('uint64_t');
        foreach ($signals as $signo) {
            $set->cdata |= 1 << ((int) $signo - 1);
        }
        $oldSet = $ffi->new('uint64_t');
        $rc = (int) $ffi->sigprocmask($mode, \FFI::addr($set), \FFI::addr($oldSet));
        if (0 !== $rc) {
            return false;
        }
        $old = self::decodeSignalSet((int) $oldSet->cdata);

        return true;
    }

    public static function waitidAvailable(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param array<string, int> $info
     */
    public static function waitid(int $idtype, int $id, array &$info, int $options): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (!\method_exists($ffi, 'waitid')) {
            return false;
        }
        $siginfo = $ffi->new('int[128]');
        $rc = (int) $ffi->waitid($idtype, $id, \FFI::addr($siginfo), $options);
        if (0 !== $rc) {
            return false;
        }
        $info = [
            'signo' => (int) $siginfo[0],
            'errno' => (int) $siginfo[1],
            'code' => (int) $siginfo[2],
        ];

        return true;
    }

    /**
     * @return list<int>
     */
    private static function decodeSignalSet(int $mask): array
    {
        $signals = [];
        for ($signo = 1; $signo < PcntlConstants::NSIG; ++$signo) {
            if (0 !== ($mask & (1 << ($signo - 1)))) {
                $signals[] = $signo;
            }
        }

        return $signals;
    }

    public static function processAvailable(): bool
    {
        return null !== self::ffi();
    }

    public static function fork(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fork();
    }

    public static function waitpid(int $pid, int &$status, int $options): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $statusVar = $ffi->new('int');
        $rc = (int) $ffi->waitpid($pid, \FFI::addr($statusVar), $options);
        $status = (int) $statusVar->cdata;

        return $rc;
    }

    public static function alarm(int $seconds): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->alarm($seconds);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public static function exec(string $path, array $args, array $env): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $argv = \array_values($args);
        \array_unshift($argv, $path);
        $argvC = self::stringVector($ffi, $argv);
        if ([] === $env) {
            $ffi->execv($path, $argvC);

            return false;
        }
        $envp = [];
        foreach ($env as $key => $value) {
            $envp[] = $key.'='.$value;
        }
        $envC = self::stringVector($ffi, $envp);
        $ffi->execve($path, $argvC, $envC);

        return false;
    }

    /**
     * @param list<string> $strings
     *
     * @return \FFI\CData
     */
    private static function stringVector(\FFI $ffi, array $strings): \FFI\CData
    {
        $n = \count($strings);
        $vec = $ffi->new('char*['.($n + 1).']');
        for ($i = 0; $i < $n; ++$i) {
            $len = \strlen($strings[$i]);
            $buf = $ffi->new('char['.($len + 1).']', false);
            \FFI::memcpy($buf, $strings[$i], $len);
            $buf[$len] = "\0";
            $vec[$i] = $buf;
        }
        $vec[$n] = null;

        return $vec;
    }

    public static function wifexited(int $status): bool
    {
        return 0 === ($status & 0x7f);
    }

    public static function wexitstatus(int $status): int
    {
        return ($status >> 8) & 0xff;
    }

    /** Linux WIFSIGNALED — ((signed char)((status & 0x7f) + 1) >> 1) > 0 */
    public static function wifsignaled(int $status): bool
    {
        $term = $status & 0x7f;

        return 0 !== $term && 0x7f !== $term;
    }

    /** Linux WIFSTOPPED — ((status & 0xff) == 0x7f) */
    public static function wifstopped(int $status): bool
    {
        return 0x7f === ($status & 0xff);
    }

    /** Linux WTERMSIG */
    public static function wtermsig(int $status): int
    {
        return $status & 0x7f;
    }

    /** Linux WSTOPSIG */
    public static function wstopsig(int $status): int
    {
        return ($status >> 8) & 0xff;
    }

    public static function priorityAvailable(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param-out int $errno
     */
    public static function getpriority(?int $pid, int $who, ?int &$errno = null): int|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            $errno = PcntlConstants::PCNTL_EINVAL;

            return false;
        }
        $processId = null === $pid ? (int) $ffi->getpid() : $pid;
        self::clearErrno($ffi);
        $pri = (int) $ffi->getpriority($who, $processId);
        $err = self::errno($ffi);
        if (0 !== $err) {
            $errno = $err;

            return false;
        }
        $errno = 0;

        return $pri;
    }

    /**
     * @param-out int $errno
     */
    public static function setpriority(int $priority, ?int $pid, int $who, ?int &$errno = null): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            $errno = PcntlConstants::PCNTL_EINVAL;

            return false;
        }
        $processId = null === $pid ? (int) $ffi->getpid() : $pid;
        self::clearErrno($ffi);
        $rc = (int) $ffi->setpriority($who, $processId, $priority);
        if (0 !== $rc) {
            $errno = self::errno($ffi);

            return false;
        }
        $errno = 0;

        return true;
    }

    public static function strerrorAvailable(): bool
    {
        return null !== self::ffi();
    }

    public static function strerror(int $error): string
    {
        $ffi = self::ffi();
        if (null === $ffi || !\method_exists($ffi, 'strerror')) {
            return 'Unknown error '.$error;
        }
        $msg = $ffi->strerror($error);

        return null === $msg ? ('Unknown error '.$error) : (string) $msg;
    }

    public static function unshareAvailable(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param-out int $errno
     */
    public static function unshare(int $flags, int &$errno): bool
    {
        $ffi = self::ffi();
        if (null === $ffi || !\method_exists($ffi, 'unshare')) {
            $errno = PcntlConstants::PCNTL_EINVAL;

            return false;
        }
        self::clearErrno($ffi);
        $rc = (int) $ffi->unshare($flags);
        $errno = self::errno($ffi);
        if (-1 === $rc) {
            return false;
        }

        return true;
    }

    public static function cpuAffinityAvailable(): bool
    {
        return null !== self::ffi();
    }

    /**
     * sched_getaffinity(2) — list of CPU ids in the process affinity mask (#20510).
     *
     * @param-out int $errno
     *
     * @return list<int>|false
     */
    public static function getcpuaffinity(int $pid, int &$errno): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            $errno = PcntlConstants::PCNTL_EINVAL;

            return false;
        }
        $mask = $ffi->new('unsigned char['.self::CPU_SET_BYTES.']');
        for ($i = 0; $i < self::CPU_SET_BYTES; ++$i) {
            $mask[$i] = 0;
        }
        self::clearErrno($ffi);
        $rc = (int) $ffi->sched_getaffinity($pid, self::CPU_SET_BYTES, $mask);
        if (0 !== $rc) {
            $errno = self::errno($ffi);

            return false;
        }
        $errno = 0;
        $maxCpus = self::configuredProcessorCount($ffi);
        $cpus = [];
        for ($cpu = 0; $cpu < $maxCpus; ++$cpu) {
            if (self::cpuIsSet($mask, $cpu)) {
                $cpus[] = $cpu;
            }
        }

        return $cpus;
    }

    /**
     * sched_setaffinity(2) (#20510).
     *
     * @param list<int> $cpuIds
     * @param-out int $errno
     */
    public static function setcpuaffinity(int $pid, array $cpuIds, int &$errno): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            $errno = PcntlConstants::PCNTL_EINVAL;

            return false;
        }
        $mask = $ffi->new('unsigned char['.self::CPU_SET_BYTES.']');
        for ($i = 0; $i < self::CPU_SET_BYTES; ++$i) {
            $mask[$i] = 0;
        }
        foreach ($cpuIds as $cpu) {
            self::cpuSet($mask, (int) $cpu);
        }
        self::clearErrno($ffi);
        $rc = (int) $ffi->sched_setaffinity($pid, self::CPU_SET_BYTES, $mask);
        if (0 !== $rc) {
            $errno = self::errno($ffi);

            return false;
        }
        $errno = 0;

        return true;
    }

    public static function getcpu(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->sched_getcpu();
    }

    public static function configuredProcessorCount(?\FFI $ffi = null): int
    {
        $ffi ??= self::ffi();
        if (null === $ffi) {
            return 1;
        }
        // _SC_NPROCESSORS_CONF = 83 on Linux
        $n = (int) $ffi->sysconf(83);

        return $n > 0 ? $n : 1;
    }

    /** Linux cpu_set_t size for CPU_SETSIZE=1024 (128 bytes). */
    private const CPU_SET_BYTES = 128;

    private static function cpuIsSet(\FFI\CData $mask, int $cpu): bool
    {
        $byte = intdiv($cpu, 8);
        $bit = $cpu % 8;
        if ($byte < 0 || $byte >= self::CPU_SET_BYTES) {
            return false;
        }

        return 0 !== (((int) $mask[$byte]) & (1 << $bit));
    }

    private static function cpuSet(\FFI\CData $mask, int $cpu): void
    {
        $byte = intdiv($cpu, 8);
        $bit = $cpu % 8;
        if ($byte < 0 || $byte >= self::CPU_SET_BYTES) {
            return;
        }
        $mask[$byte] = ((int) $mask[$byte]) | (1 << $bit);
    }

    private static function clearErrno(\FFI $ffi): void
    {
        $errnoPtr = $ffi->__errno_location();
        $errnoPtr[0] = 0;
    }

    private static function errno(\FFI $ffi): int
    {
        return (int) $ffi->__errno_location()[0];
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;
typedef unsigned int id_t;
typedef void (*sighandler_t)(int);
typedef unsigned long sigset_t;
sighandler_t signal(int signum, sighandler_t handler);
int sigprocmask(int how, const sigset_t *set, sigset_t *oldset);
pid_t fork(void);
pid_t waitpid(pid_t pid, int *status, int options);
pid_t getpid(void);
int getpriority(int which, id_t who);
int setpriority(int which, id_t who, int prio);
int *__errno_location(void);
char *strerror(int errnum);
int unshare(int flags);
unsigned int alarm(unsigned int seconds);
int execv(const char *path, char *const argv[]);
int execve(const char *path, char *const argv[], char *const envp[]);
typedef int idtype_t;
typedef struct { int si_signo; int si_errno; int si_code; } siginfo_t;
int waitid(idtype_t idtype, id_t id, siginfo_t *infop, int options);
long sysconf(int name);
int sched_getaffinity(pid_t pid, unsigned long cpusetsize, void *mask);
int sched_setaffinity(pid_t pid, unsigned long cpusetsize, const void *mask);
int sched_getcpu(void);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$unavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
