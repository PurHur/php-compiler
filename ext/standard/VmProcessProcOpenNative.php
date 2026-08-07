<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * VM proc_open()/proc_close()/proc_get_status()/proc_terminate() — libc FFI, no host proc_* (#8652, #8889).
 *
 * SSOT for compiled JIT/AOT via {@see ProcessOpenJitHelper}; mirrors {@see VmProcessExecCaptureNative}.
 * php-src: ext/standard/proc_open.c
 */
final class VmProcessProcOpenNative
{
    private const MAX_HANDLES = 64;

    private const EXIT_127 = 127;

    private const WNOHANG = 1;

    /** waitpid: report stopped children (Linux WUNTRACED). */
    private const WUNTRACED = 2;

    /** Linux SIGCONT / SIGSTOP — pause child until parent pipe setup completes (php-src proc_open race). */
    private const SIGCONT = 18;

    private const SIGSTOP = 19;

    /** @var array<int, array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles: list<int>, childPaused: bool, pendingSignals?: list<int>}> */
    private static array $slots = [];

    private static int $nextHandleId = 0;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /** @internal {@see ProcessSlotJitHelper} embed slot table (#9408) */
    public static function sharedFfi(): ?\FFI
    {
        return self::ffi();
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$slots[$handle]) && self::$slots[$handle]['active'];
    }

    public static function hasHandle(int $handle): bool
    {
        return isset(self::$slots[$handle]);
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

        // php-src: proc_get_status()['command'] is argv[0], not the joined command line.
        $commandLabel = $argv[0];

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
                $ffi->raise(self::SIGSTOP);
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
                self::execArgv($ffi, $argv, $env);
                $ffi->_exit(self::EXIT_127);
            }

            // Wait until raise(SIGSTOP) lands — an early SIGCONT is lost and leaves the
            // child stopped with the parent's stdout still open (#25195 / #24481).
            if (!self::waitForChildStopped($ffi, $pid)) {
                self::killAndWait($ffi, $pid);
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                self::releaseSlot($slot);

                return false;
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
                'pipeHandles' => array_values($pipeHandles),
                'childPaused' => true,
            ];
            // Resume before return — a SIGSTOP'd fork child still holds the parent's
            // stdout/stderr FDs; PHPUnit's harness blocks forever on EOF (#24481).
            self::resumeChildIfPaused($ffi, self::$slots[$slot]);

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
                $ffi->raise(self::SIGSTOP);
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
                self::execArgv($ffi, ['sh', '-c', $command], $env);
                $ffi->_exit(self::EXIT_127);
            }

            // Wait until raise(SIGSTOP) lands — an early SIGCONT is lost and leaves the
            // child stopped with the parent's stdout still open (#25195 / #24481).
            if (!self::waitForChildStopped($ffi, $pid)) {
                self::killAndWait($ffi, $pid);
                self::closePipePair($ffi, $stdinPipe);
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);
                self::releaseSlot($slot);

                return false;
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
                'pipeHandles' => array_values($pipeHandles),
                'childPaused' => true,
            ];
            // Resume before return — a SIGSTOP'd fork child still holds the parent's
            // stdout/stderr FDs; PHPUnit's harness blocks forever on EOF (#24481).
            self::resumeChildIfPaused($ffi, self::$slots[$slot]);

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
        self::closeRemainingPipeHandles($slot);
        self::resumeChildIfPaused($ffi, $slot);

        if ($slot['statusKnown']) {
            // php-src: proc_close() after proc_get_status() already reaped child — return -1 (#16968).
            self::$slots[$handle] = $slot;

            return -1;
        }

        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($slot['pid'], \FFI::addr($status), 0);
            if (-1 === $waitRc) {
                self::releaseSlot($handle);

                return -1;
            }
            $slot['statusKnown'] = true;
            $slot['status'] = (int) $status->cdata;
            self::$slots[$handle] = $slot;

            return self::exitCodeFromStatus($slot['status']);
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
        if (null === $slot) {
            return false;
        }
        if (!$slot['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        // php-src 8.2: waitpid already reaped → ECHILD path: running=false, exitcode=-1 (#23722).
        // Only the first post-exit proc_get_status() returns the real exitcode.
        if ($slot['statusKnown']) {
            $pendingSignals = self::resolvePendingSignals($slot, false, false, 0);
            self::$slots[$handle] = $slot;

            return self::buildProcStatusArray(
                $slot['command'],
                $slot['pid'],
                false,
                false,
                false,
                -1,
                0,
                0,
                $pendingSignals,
                true,
            );
        }

        $running = true;
        $statusVal = 0;
        self::pollChildExitStatus($ffi, $slot);
        self::$slots[$handle] = $slot;
        if ($slot['statusKnown']) {
            $statusVal = $slot['status'];
            $running = false;
        } else {
            // php-src: waitpid(WNOHANG) in proc_get_status; reap only when child already exited (#13079, #15647).
            try {
                $running = 0 === (int) $ffi->kill($slot['pid'], 0);
            } catch (\Throwable) {
                return false;
            }
        }

        $lowByte = $statusVal & 0xff;
        $exited = 0 === $lowByte;
        $stopped = 0x7f === $lowByte;
        $signaled = $lowByte > 0 && !$stopped;
        $signals = self::termsigStopsigFromWaitStatus($statusVal);

        $pendingSignals = self::resolvePendingSignals($slot, $signaled, $stopped, $signals['termsig']);
        self::$slots[$handle] = $slot;

        return self::buildProcStatusArray(
            $slot['command'],
            $slot['pid'],
            $running,
            $signaled,
            $stopped,
            $running ? -1 : ($exited ? (($statusVal >> 8) & 0xff) : -1),
            $signals['termsig'],
            $signals['stopsig'],
            $pendingSignals,
            $slot['statusKnown'],
        );
    }

    /**
     * @param array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles?: list<int>, childPaused?: bool, pendingSignals?: list<int>} $slot
     *
     * @return array<string, mixed>|false
     */
    public static function statusFromClosedSlotForEmbed(array $slot): array|false
    {
        return self::statusFromClosedSlot($slot);
    }

    /**
     * php-src: proc_get_status() on proc_close()d handle — cached exit snapshot, running=false (#16863).
     *
     * @param array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles?: list<int>, childPaused?: bool, pendingSignals?: list<int>} $slot
     *
     * @return array<string, mixed>|false
     */
    private static function statusFromClosedSlot(array $slot): array|false
    {
        if (!$slot['statusKnown']) {
            return false;
        }

        $statusVal = $slot['status'];
        $lowByte = $statusVal & 0xff;
        $exited = 0 === $lowByte;
        $stopped = 0x7f === $lowByte;
        $signaled = $lowByte > 0 && !$stopped;
        $signals = self::termsigStopsigFromWaitStatus($statusVal);
        $pendingSignals = self::resolvePendingSignals($slot, $signaled, $stopped, $signals['termsig']);

        return self::buildProcStatusArray(
            $slot['command'],
            $slot['pid'],
            false,
            $signaled,
            $stopped,
            $exited ? (($statusVal >> 8) & 0xff) : -1,
            $signals['termsig'],
            $signals['stopsig'],
            $pendingSignals,
            true,
        );
    }

    /**
     * php-src ext/standard/proc_open.c — PHP_FUNCTION(proc_get_status) array insertion order
     * (#13210, #17362, #28527): command, pid, [cached], running, signaled, stopped, exitcode, termsig, stopsig.
     *
     * @param list<int> $pendingSignals unused — pending_signals never shipped in php-src (#28527)
     *
     * @return array<string, mixed>
     */
    public static function buildProcStatusArray(
        string $command,
        int $pid,
        bool $running,
        bool $signaled,
        bool $stopped,
        int $exitcode,
        int $termsig,
        int $stopsig,
        array $pendingSignals = [],
        bool $cached = false,
    ): array {
        $status = [
            'command' => $command,
            'pid' => $pid,
        ];
        // php-src inserts cached immediately after pid (GH-10239).
        if (CompilerVersion::supportsProcGetStatusCached()) {
            $status['cached'] = $cached;
        }
        $status['running'] = $running;
        $status['signaled'] = $signaled;
        $status['stopped'] = $stopped;
        $status['exitcode'] = $exitcode;
        $status['termsig'] = $termsig;
        $status['stopsig'] = $stopsig;
        // pending_signals was a compiler phantom (#16707/#17907); Zend 8.3–8.5 omit it (#28527).
        if (CompilerVersion::supportsProcGetStatusPendingSignals()) {
            $status['pending_signals'] = $pendingSignals;
        }

        return $status;
    }

    /**
     * Signals sent via proc_terminate() but not yet delivered to the child (php-src proc_open.c, #16707).
     *
     * @param array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles?: list<int>, childPaused?: bool, pendingSignals?: list<int>} $slot
     *
     * @return list<int>
     */
    public static function resolvePendingSignals(array &$slot, bool $signaled, bool $stopped, int $termsig): array
    {
        if (!CompilerVersion::supportsProcGetStatusPendingSignals()) {
            return [];
        }

        $pending = $slot['pendingSignals'] ?? [];
        if ($signaled && $termsig > 0) {
            $pending = array_values(array_filter(
                $pending,
                static fn (int $signal): bool => $signal !== $termsig,
            ));
        }
        if (!$stopped && !$signaled) {
            $pending = [];
        }
        $slot['pendingSignals'] = $pending;

        return $pending;
    }

    /**
     * WTERMSIG / WSTOPSIG parity for proc_get_status() (php-src ext/standard/proc_open.c).
     *
     * @return array{termsig: int, stopsig: int}
     */
    public static function termsigStopsigFromWaitStatus(int $statusVal): array
    {
        $lowByte = $statusVal & 0xff;
        if (0x7f === $lowByte) {
            return ['termsig' => 0, 'stopsig' => ($statusVal >> 8) & 0xff];
        }
        if ($lowByte > 0) {
            return ['termsig' => $lowByte, 'stopsig' => 0];
        }

        return ['termsig' => 0, 'stopsig' => 0];
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

        // Always SIGCONT before the requested signal. open()'s resume can race ahead of
        // raise(SIGSTOP); SIGTERM alone does not wake a stopped child, so the orphan keeps
        // the parent's stdout open and the compliance harness blocks on EOF (#25195).
        self::sendSigcont($ffi, $slot);
        self::$slots[$handle] = $slot;

        try {
            return 0 === (int) $ffi->kill($slot['pid'], $signal);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function exitCodeFromStatus(int $statusVal): int
    {
        $lowByte = $statusVal & 0xff;
        if (0 === $lowByte) {
            return ($statusVal >> 8) & 0xff;
        }
        if (0x7f === $lowByte) {
            return -1;
        }

        return $lowByte;
    }

    /**
     * php-src proc_open_rsrc_dtor — close pipe streams before waitpid (ext/standard/proc_open.c).
     *
     * @param array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles?: list<int>, childPaused?: bool} $slot
     */
    private static function closeRemainingPipeHandles(array &$slot): void
    {
        foreach ($slot['pipeHandles'] ?? [] as $pipeHandle) {
            if (!\is_int($pipeHandle) || !VmPhpFdStream::isValidHandle($pipeHandle)) {
                continue;
            }
            VmFs::fclose($pipeHandle);
        }
        $slot['pipeHandles'] = [];
    }

    /**
     * php-src php_array_to_envp() — KEY=value pairs for execvpe (ext/standard/proc_open.c).
     *
     * @param array<string, string> $env
     */
    private static function buildEnvp(\FFI $ffi, array $env): \FFI\CData
    {
        $pairs = [];
        foreach ($env as $key => $value) {
            if (!\is_string($key) || !\is_string($value) || '' === $key || '' === $value) {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }
        $count = \count($pairs);
        $envp = $ffi->new('char*['.($count + 1).']');
        foreach ($pairs as $i => $pair) {
            $len = \strlen($pair);
            $buf = $ffi->new('char['.($len + 1).']', false);
            \FFI::memcpy($buf, $pair, $len);
            $buf[$len] = "\0";
            $envp[$i] = \FFI::cast('char*', $buf);
        }
        $envp[$count] = null;

        return $envp;
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

    /** Last proc pipe closed — resume paused child so short-lived commands can exit (#15647). */
    public static function onPipeHandleClosed(int $pipeHandle): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        foreach (self::$slots as $handle => &$slot) {
            if (!$slot['active']) {
                continue;
            }
            $pipes = $slot['pipeHandles'] ?? [];
            $idx = array_search($pipeHandle, $pipes, true);
            if (false === $idx) {
                continue;
            }
            unset($pipes[$idx]);
            $slot['pipeHandles'] = array_values($pipes);
            if ([] === $slot['pipeHandles']) {
                self::resumeChildIfPaused($ffi, $slot);
            }
            self::$slots[$handle] = $slot;

            return;
        }
    }

    /** Resume paused child when parent performs blocking I/O on a proc pipe (#14685, #15084). */
    public static function resumeChildForPipeHandle(int $pipeHandle): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        foreach (self::$slots as &$slot) {
            if (!$slot['active']) {
                continue;
            }
            if (!\in_array($pipeHandle, $slot['pipeHandles'] ?? [], true)) {
                continue;
            }
            self::resumeChildIfPaused($ffi, $slot);

            return;
        }
    }

    /**
     * Non-blocking waitpid — reap exited child and cache status for proc_close() (#15647).
     *
     * @param array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pipeHandles?: list<int>, childPaused?: bool} $slot
     */
    private static function pollChildExitStatus(\FFI $ffi, array &$slot): void
    {
        if ($slot['statusKnown']) {
            return;
        }
        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($slot['pid'], \FFI::addr($status), self::WNOHANG);
            if ($waitRc === $slot['pid']) {
                $slot['statusKnown'] = true;
                $slot['status'] = (int) $status->cdata;
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Block until the fork child has stopped for raise(SIGSTOP).
     * Prevents a lost SIGCONT race that leaves the child in T with parent FDs (#25195).
     */
    private static function waitForChildStopped(\FFI $ffi, int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($pid, \FFI::addr($status), self::WUNTRACED);
            if ($waitRc !== $pid) {
                return false;
            }
            // WIFSTOPPED: (status & 0xff) == 0x7f
            return 0x7f === ((int) $status->cdata & 0xff);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Child raises SIGSTOP at fork; resume on blocking pipe I/O or proc_close() (#14685, #15035). */
    private static function resumeChildIfPaused(\FFI $ffi, array &$slot): void
    {
        if (!($slot['childPaused'] ?? false)) {
            return;
        }
        self::sendSigcont($ffi, $slot);
    }

    /** @param array{pid: int, childPaused?: bool} $slot */
    private static function sendSigcont(\FFI $ffi, array &$slot): void
    {
        $pid = $slot['pid'];
        if ($pid > 0) {
            try {
                $ffi->kill($pid, self::SIGCONT);
            } catch (\Throwable) {
            }
        }
        $slot['childPaused'] = false;
    }

    /**
     * @param list<string> $argv
     * @param array<string, string>|null $env
     */
    private static function execArgv(\FFI $ffi, array $argv, ?array $env = null): void
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
        if (null === $env) {
            $ffi->execvp($filePtr, $argvPtr);

            return;
        }
        $envp = self::buildEnvp($ffi, $env);
        $ffi->execvpe($filePtr, $argvPtr, $envp);
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
int execvpe(const char *file, char *const argv[], char *const envp[]);
void _exit(int status);
pid_t waitpid(pid_t pid, int *status, int options);
int kill(pid_t pid, int sig);
int raise(int sig);
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
