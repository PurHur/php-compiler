<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM proc_open()/proc_close()/proc_get_status()/proc_terminate() — libc FFI, no host proc_* (#8652, #8889).
 *
 * Mirrors JIT {@see \PHPCompiler\JIT\Builtin\ProcessOpenJit} and {@see VmProcessExecCaptureNative}.
 * php-src: ext/standard/proc_open.c
 */
final class VmProcessProcOpenNative
{
    private const MAX_HANDLES = 64;

    private const EXIT_127 = 127;

    private const WNOHANG = 1;

    /** @var array<int, array{pid: int, command: string, statusKnown: bool, status: int, active: bool}> */
    private static array $slots = [];

    private static int $nextHandleId = 0;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$slots[$handle]) && self::$slots[$handle]['active'];
    }

    /**
     * @param list<string> $argv
     * @param array<int, array{0: string, 1?: string}> $descriptorSpec
     * @param array<string, string>|null $env
     *
     * @return array{0: int, 1: array<int, int>}|false
     */
    public static function openArgv(
        array $argv,
        array $descriptorSpec,
        ?string $cwd = null,
        ?array $env = null,
    ): array|false {
        if ([] === $argv || '' === $argv[0]) {
            return false;
        }

        $commandLabel = implode(' ', $argv);

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $slot = self::allocateSlot();
        if (null === $slot) {
            return false;
        }

        try {
            $stdinPipe = $ffi->new('int[2]');
            $stdoutPipe = $ffi->new('int[2]');
            $stderrPipe = $ffi->new('int[2]');
            if (0 !== (int) $ffi->pipe($stdinPipe)
                || 0 !== (int) $ffi->pipe($stdoutPipe)
                || 0 !== (int) $ffi->pipe($stderrPipe)) {
                self::releaseSlot($slot);

                return false;
            }

            $pid = (int) $ffi->fork();
            if (-1 === $pid) {
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                self::releaseSlot($slot);

                return false;
            }

            if (0 === $pid) {
                self::closePipeWrite($ffi, $stdinPipe);
                self::closePipeRead($ffi, $stdoutPipe);
                self::closePipeRead($ffi, $stderrPipe);
                $ffi->dup2((int) $stdinPipe[0], 0);
                $ffi->dup2((int) $stdoutPipe[1], 1);
                $ffi->dup2((int) $stderrPipe[1], 2);
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                if (null !== $cwd && '' !== $cwd) {
                    $ffi->chdir($cwd);
                }
                if (null !== $env) {
                    self::applyEnv($ffi, $env);
                }
                self::execArgv($ffi, $argv);
                $ffi->_exit(self::EXIT_127);
            }

            self::closePipeRead($ffi, $stdinPipe);
            self::closePipeWrite($ffi, $stdoutPipe);
            self::closePipeWrite($ffi, $stderrPipe);

            $pipeFds = [
                0 => (int) $stdinPipe[1],
                1 => (int) $stdoutPipe[0],
                2 => (int) $stderrPipe[0],
            ];
            $pipeHandles = [];
            foreach ($descriptorSpec as $fd => $cells) {
                if ('pipe' !== ($cells[0] ?? '')) {
                    continue;
                }
                $osFd = $pipeFds[$fd] ?? null;
                if (null === $osFd || $osFd < 0) {
                    continue;
                }
                $mode = match ($fd) {
                    0 => 'w',
                    default => 'r',
                };
                $dupFd = (int) $ffi->dup($osFd);
                if ($dupFd < 0) {
                    self::killAndWait($ffi, $pid);
                    self::closeRemainingPipeFds($ffi, $pipeFds);
                    self::releaseSlot($slot);

                    return false;
                }
                $handle = VmPhpFdStream::adopt($dupFd, 'pipe://proc_open/'.$fd, $mode);
                if (false === $handle) {
                    $ffi->close($dupFd);
                    self::killAndWait($ffi, $pid);
                    self::closeRemainingPipeFds($ffi, $pipeFds);
                    self::releaseSlot($slot);

                    return false;
                }
                $pipeHandles[$fd] = $handle;
            }

            foreach ($pipeFds as $osFd) {
                if ($osFd >= 0) {
                    $ffi->close($osFd);
                }
            }

            self::$slots[$slot] = [
                'pid' => $pid,
                'command' => $commandLabel,
                'statusKnown' => false,
                'status' => 0,
                'active' => true,
            ];

            return [$slot, $pipeHandles];
        } catch (\Throwable) {
            self::releaseSlot($slot);

            return false;
        }
    }

    /**
     * @param array<int, array{0: string, 1?: string}> $descriptorSpec
     * @param array<string, string>|null $env
     *
     * @return array{0: int, 1: array<int, int>}|false
     */
    public static function open(
        string $command,
        array $descriptorSpec,
        ?string $cwd = null,
        ?array $env = null,
    ): array|false {
        if ('' === $command) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $slot = self::allocateSlot();
        if (null === $slot) {
            return false;
        }

        try {
            $stdinPipe = $ffi->new('int[2]');
            $stdoutPipe = $ffi->new('int[2]');
            $stderrPipe = $ffi->new('int[2]');
            if (0 !== (int) $ffi->pipe($stdinPipe)
                || 0 !== (int) $ffi->pipe($stdoutPipe)
                || 0 !== (int) $ffi->pipe($stderrPipe)) {
                self::releaseSlot($slot);

                return false;
            }

            $pid = (int) $ffi->fork();
            if (-1 === $pid) {
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                self::releaseSlot($slot);

                return false;
            }

            if (0 === $pid) {
                self::closePipeWrite($ffi, $stdinPipe);
                self::closePipeRead($ffi, $stdoutPipe);
                self::closePipeRead($ffi, $stderrPipe);
                $ffi->dup2((int) $stdinPipe[0], 0);
                $ffi->dup2((int) $stdoutPipe[1], 1);
                $ffi->dup2((int) $stderrPipe[1], 2);
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                if (null !== $cwd && '' !== $cwd) {
                    $ffi->chdir($cwd);
                }
                if (null !== $env) {
                    self::applyEnv($ffi, $env);
                }
                $ffi->execl('/bin/sh', 'sh', '-c', $command, null);
                $ffi->_exit(self::EXIT_127);
            }

            self::closePipeRead($ffi, $stdinPipe);
            self::closePipeWrite($ffi, $stdoutPipe);
            self::closePipeWrite($ffi, $stderrPipe);

            $pipeFds = [
                0 => (int) $stdinPipe[1],
                1 => (int) $stdoutPipe[0],
                2 => (int) $stderrPipe[0],
            ];
            $pipeHandles = [];
            foreach ($descriptorSpec as $fd => $cells) {
                if ('pipe' !== ($cells[0] ?? '')) {
                    continue;
                }
                $osFd = $pipeFds[$fd] ?? null;
                if (null === $osFd || $osFd < 0) {
                    continue;
                }
                $mode = match ($fd) {
                    0 => 'w',
                    default => 'r',
                };
                $dupFd = (int) $ffi->dup($osFd);
                if ($dupFd < 0) {
                    self::killAndWait($ffi, $pid);
                    self::closeRemainingPipeFds($ffi, $pipeFds);
                    self::releaseSlot($slot);

                    return false;
                }
                $handle = VmPhpFdStream::adopt($dupFd, 'pipe://proc_open/'.$fd, $mode);
                if (false === $handle) {
                    $ffi->close($dupFd);
                    self::killAndWait($ffi, $pid);
                    self::closeRemainingPipeFds($ffi, $pipeFds);
                    self::releaseSlot($slot);

                    return false;
                }
                $pipeHandles[$fd] = $handle;
            }

            foreach ($pipeFds as $osFd) {
                if ($osFd >= 0) {
                    $ffi->close($osFd);
                }
            }

            self::$slots[$slot] = [
                'pid' => $pid,
                'command' => $command,
                'statusKnown' => false,
                'status' => 0,
                'active' => true,
            ];

            return [$slot, $pipeHandles];
        } catch (\Throwable) {
            self::releaseSlot($slot);

            return false;
        }
    }

    public static function close(int $handle): int
    {
        $slot = self::$slots[$handle] ?? null;
        if (null === $slot || !$slot['active']) {
            return -1;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $slot['active'] = false;
        self::$slots[$handle] = $slot;

        if ($slot['statusKnown']) {
            $status = $slot['status'];
            self::releaseSlot($handle);

            return self::exitCodeFromStatus($status);
        }

        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($slot['pid'], \FFI::addr($status), 0);
            self::releaseSlot($handle);
            if (-1 === $waitRc) {
                return -1;
            }

            return self::exitCodeFromStatus((int) $status->cdata);
        } catch (\Throwable) {
            self::releaseSlot($handle);

            return -1;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getStatus(int $handle): array|false
    {
        $slot = self::$slots[$handle] ?? null;
        if (null === $slot || !$slot['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $running = true;
        $statusVal = 0;

        if ($slot['statusKnown']) {
            $statusVal = $slot['status'];
            $running = false;
        } else {
            try {
                $status = $ffi->new('int');
                $waitRc = (int) $ffi->waitpid($slot['pid'], \FFI::addr($status), self::WNOHANG);
                if ($waitRc > 0) {
                    $statusVal = (int) $status->cdata;
                    $slot['status'] = $statusVal;
                    $slot['statusKnown'] = true;
                    self::$slots[$handle] = $slot;
                    $running = false;
                } elseif (-1 === $waitRc) {
                    $running = 0 === (int) $ffi->kill($slot['pid'], 0);
                }
            } catch (\Throwable) {
                return false;
            }
        }

        $lowByte = $statusVal & 0xff;
        $exited = 0 === $lowByte;
        $stopped = 0x7f === $lowByte;
        $signaled = $lowByte > 0 && !$stopped;

        return [
            'command' => $slot['command'],
            'pid' => $slot['pid'],
            'running' => $running,
            'exitcode' => $running ? -1 : ($exited ? (($statusVal >> 8) & 0xff) : -1),
            'signaled' => $signaled,
            'stopped' => $stopped,
        ];
    }

    public static function terminate(int $handle, int $signal = 15): bool
    {
        $slot = self::$slots[$handle] ?? null;
        if (null === $slot || !$slot['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->kill($slot['pid'], $signal);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function exitCodeFromStatus(int $statusVal): int
    {
        if (0 === ($statusVal & 0xff)) {
            return ($statusVal >> 8) & 0xff;
        }

        return self::EXIT_127;
    }

    /**
     * @param array<string, string> $env
     */
    private static function applyEnv(\FFI $ffi, array $env): void
    {
        foreach ($env as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }
            $ffi->setenv($key, $value, 1);
        }
    }

    private static function allocateSlot(): ?int
    {
        if (\count(self::$slots) >= self::MAX_HANDLES) {
            return null;
        }

        return ++self::$nextHandleId;
    }

    private static function releaseSlot(int $handle): void
    {
        unset(self::$slots[$handle]);
    }

    /** @param \FFI\CData $pipe int[2] */
    private static function closePipePair(\FFI $ffi, \FFI\CData $pipe): void
    {
        $ffi->close((int) $pipe[0]);
        $ffi->close((int) $pipe[1]);
    }

    /** @param \FFI\CData $pipe int[2] */
    private static function closePipeRead(\FFI $ffi, \FFI\CData $pipe): void
    {
        $ffi->close((int) $pipe[0]);
    }

    /** @param \FFI\CData $pipe int[2] */
    private static function closePipeWrite(\FFI $ffi, \FFI\CData $pipe): void
    {
        $ffi->close((int) $pipe[1]);
    }

    /** @param array<int, int> $pipeFds */
    private static function closeRemainingPipeFds(\FFI $ffi, array $pipeFds): void
    {
        foreach ($pipeFds as $fd) {
            if ($fd >= 0) {
                $ffi->close($fd);
            }
        }
    }

    private static function killAndWait(\FFI $ffi, int $pid): void
    {
        $ffi->kill($pid, 9);
        $status = $ffi->new('int');
        $ffi->waitpid($pid, \FFI::addr($status), 0);
    }

    /** @param list<string> $argv */
    private static function execArgv(\FFI $ffi, array $argv): void
    {
        $argc = \count($argv);
        $argvPtr = $ffi->new('char*['.($argc + 1).']');
        $filePtr = null;
        foreach ($argv as $i => $arg) {
            $len = \strlen($arg);
            $buf = $ffi->new('char['.($len + 1).']', false);
            \FFI::memcpy($buf, $arg, $len);
            $buf[$len] = "\0";
            $cast = \FFI::cast('char*', $buf);
            $argvPtr[$i] = $cast;
            if (0 === $i) {
                $filePtr = $cast;
            }
        }
        $argvPtr[$argc] = null;
        $ffi->execvp($filePtr, $argvPtr);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;

int pipe(int pipefd[2]);
pid_t fork(void);
int dup(int oldfd);
int dup2(int oldfd, int newfd);
int close(int fd);
int chdir(const char *path);
int setenv(const char *name, const char *value, int overwrite);
int execl(const char *path, const char *arg, ...);
int execvp(const char *file, char *const argv[]);
void _exit(int status);
pid_t waitpid(pid_t pid, int *status, int options);
int kill(pid_t pid, int sig);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
