<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;

/** VM pcntl_signal()/pcntl_signal_dispatch() (php-src ext/pcntl/pcntl.c; issue #6680, #6545). */
final class VmPcntl
{
    /** @var array<int, array{kind: 'closure', closure: ClosureState, source: Variable}|array{kind: 'callable', callable: Variable}> */
    private static array $handlers = [];

    /** @var array<int, int> SIG_DFL / SIG_IGN dispositions when no user handler is registered */
    private static array $dispositions = [];

    /** @var list<int> Blocked signal numbers (VM fallback when host sigprocmask unavailable). */
    private static array $blockedSignals = [];

    private static bool $asyncSignals = false;

    /** @var list<int> */
    private static array $pending = [];

    /** Last errno from a failed pcntl_* call (php-src PCNTL_G(last_error); #20061). */
    private static int $lastError = 0;

    public static function available(): bool
    {
        return true;
    }

    public static function getLastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $errno): void
    {
        self::$lastError = $errno;
    }

    public static function syncLastErrorFromHost(): void
    {
        if (\function_exists('pcntl_get_last_error')) {
            self::$lastError = (int) \pcntl_get_last_error();
        }
    }

    /**
     * php-src pcntl_strerror() — strerror(3) (ext/pcntl/pcntl.c; #20061).
     */
    public static function strerror(int $error): string
    {
        if (PcntlHostBridge::strerrorAvailable()) {
            return PcntlHostBridge::strerror($error);
        }
        if (PcntlLibcThinAbi::strerrorAvailable()) {
            return PcntlLibcThinAbi::strerror($error);
        }

        return self::strerrorFallback($error);
    }

    /**
     * php-src pcntl_unshare() — unshare(2) (ext/pcntl/pcntl.c; #20061).
     */
    public static function unshare(int $flags): bool
    {
        if (PcntlHostBridge::unshareAvailable()) {
            try {
                $ok = PcntlHostBridge::unshare($flags);
            } catch (\ValueError $e) {
                self::syncLastErrorFromHost();
                throw $e;
            }
            if (!$ok) {
                self::syncLastErrorFromHost();
            }

            return $ok;
        }
        if (PcntlLibcThinAbi::unshareAvailable()) {
            $errno = 0;
            $ok = PcntlLibcThinAbi::unshare($flags, $errno);
            if (!$ok) {
                self::$lastError = $errno;
                if (PcntlConstants::PCNTL_EINVAL === $errno) {
                    throw new \ValueError(
                        'pcntl_unshare(): Argument #1 ($flags) must be a combination of CLONE_* flags, or at least one flag is unsupported by the kernel'
                    );
                }
            }

            return $ok;
        }

        throw new \Error('pcntl_unshare() is not available in this compiler build');
    }

    /**
     * php-src pcntl_setns() — pidfd_open(2) + setns(2) (ext/pcntl/pcntl.c; #21257).
     */
    public static function setns(?int $pid, int $nstype): bool
    {
        if (PcntlHostBridge::setnsAvailable()) {
            try {
                $ok = PcntlHostBridge::setns($pid, $nstype);
            } catch (\ValueError $e) {
                self::syncLastErrorFromHost();
                throw $e;
            }
            if (!$ok) {
                self::syncLastErrorFromHost();
            }

            return $ok;
        }
        if (PcntlLibcThinAbi::setnsAvailable()) {
            $processId = null === $pid ? PcntlLibcThinAbi::getpid() : $pid;
            $errno = 0;
            $stage = '';
            $ok = PcntlLibcThinAbi::setns($processId, $nstype, $errno, $stage);
            if ($ok) {
                return true;
            }
            self::$lastError = $errno;
            if ('pidfd' === $stage) {
                if (PcntlConstants::PCNTL_EINVAL === $errno || PcntlConstants::PCNTL_ESRCH === $errno) {
                    throw new \ValueError(
                        \sprintf(
                            'pcntl_setns(): Argument #1 ($process_id) is not a valid process (%d)',
                            $processId
                        )
                    );
                }
                self::setnsWarnPidfd($errno);

                return false;
            }
            if ('setns' === $stage) {
                if (PcntlConstants::PCNTL_ESRCH === $errno) {
                    throw new \ValueError(
                        \sprintf(
                            'pcntl_setns(): Argument #1 ($process_id) process no longer available (%d)',
                            $processId
                        )
                    );
                }
                if (PcntlConstants::PCNTL_EINVAL === $errno) {
                    throw new \ValueError(
                        \sprintf(
                            'pcntl_setns(): Argument #2 ($nstype) is an invalid nstype (%d)',
                            $nstype
                        )
                    );
                }
                self::setnsWarnSetns($errno);

                return false;
            }
            self::setnsWarnSetns($errno);

            return false;
        }

        throw new \Error('pcntl_setns() is not available in this compiler build');
    }

    private static function setnsWarnPidfd(int $errno): void
    {
        if (PcntlConstants::PCNTL_ENFILE === $errno) {
            \trigger_error(
                \sprintf('pcntl_setns(): Error %d: File descriptors per-process limit reached', $errno),
                \E_USER_WARNING
            );

            return;
        }
        if (19 === $errno) { // ENODEV
            \trigger_error(
                \sprintf('pcntl_setns(): Error %d: Anonymous inode fs unsupported', $errno),
                \E_USER_WARNING
            );

            return;
        }
        if (PcntlConstants::PCNTL_ENOMEM === $errno) {
            \trigger_error(
                \sprintf('pcntl_setns(): Error %d: Insufficient memory for pidfd_open', $errno),
                \E_USER_WARNING
            );

            return;
        }
        \trigger_error(\sprintf('pcntl_setns(): Error %d', $errno), \E_USER_WARNING);
    }

    private static function setnsWarnSetns(int $errno): void
    {
        if (PcntlConstants::PCNTL_EPERM === $errno) {
            \trigger_error(
                \sprintf('pcntl_setns(): Error %d: No required capability for this process', $errno),
                \E_USER_WARNING
            );

            return;
        }
        \trigger_error(\sprintf('pcntl_setns(): Error %d', $errno), \E_USER_WARNING);
    }

    /** Locale-stable English fallbacks for PCNTL_E* when host/FFI strerror is unavailable. */
    private static function strerrorFallback(int $error): string
    {
        static $map = [
            0 => 'Success',
            1 => 'Operation not permitted',
            2 => 'No such file or directory',
            3 => 'No such process',
            4 => 'Interrupted system call',
            5 => 'Input/output error',
            7 => 'Argument list too long',
            8 => 'Exec format error',
            10 => 'No child processes',
            11 => 'Resource temporarily unavailable',
            12 => 'Cannot allocate memory',
            13 => 'Permission denied',
            14 => 'Bad address',
            20 => 'Not a directory',
            21 => 'Is a directory',
            22 => 'Invalid argument',
            23 => 'Too many open files in system',
            24 => 'Too many open files',
            26 => 'Text file busy',
            28 => 'No space left on device',
            36 => 'File name too long',
            40 => 'Too many levels of symbolic links',
            80 => 'Accessing a corrupted shared library',
            87 => 'Too many users',
        ];

        return $map[$error] ?? ('Unknown error '.$error);
    }

    public static function processAvailable(): bool
    {
        return PcntlHostBridge::forkAvailable() || PcntlLibcThinAbi::processAvailable();
    }

    public static function fork(): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::fork();
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::fork();
        }

        throw new \Error('pcntl_fork() is not available in this compiler build');
    }

    /**
     * @param array<string, mixed>|null $resourceUsage
     */
    public static function waitpid(
        int $pid,
        int &$status,
        int $options,
        bool $captureRusage = false,
        ?array &$resourceUsage = null
    ): int {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::waitpid($pid, $status, $options, $captureRusage, $resourceUsage);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            $rc = PcntlLibcThinAbi::waitpid($pid, $status, $options);
            // Thin ABI has waitpid(2) only — Zend fills rusage via wait4; empty on capture matches
            // the no-child path and keeps arity/named-arg writeback working (#27849).
            if ($captureRusage) {
                $resourceUsage = [];
            }

            return $rc;
        }

        throw new \Error('pcntl_waitpid() is not available in this compiler build');
    }

    /** php-src pcntl_wait() — waitpid(-1, …) (ext/pcntl/pcntl.c; #19565). */
    public static function wait(int &$status, int $options): int
    {
        return self::waitpid(-1, $status, $options);
    }

    public static function alarm(int $seconds): int
    {
        if (PcntlHostBridge::alarmAvailable()) {
            return PcntlHostBridge::alarm($seconds);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::alarm($seconds);
        }

        throw new \Error('pcntl_alarm() is not available in this compiler build');
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public static function exec(string $path, array $args, array $env): bool
    {
        if (PcntlHostBridge::execAvailable()) {
            return PcntlHostBridge::exec($path, $args, $env);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::exec($path, $args, $env);
        }

        throw new \Error('pcntl_exec() is not available in this compiler build');
    }

    public static function wifexited(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifexited($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifexited($status);
        }

        throw new \Error('pcntl_wifexited() is not available in this compiler build');
    }

    public static function wexitstatus(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wexitstatus($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wexitstatus($status);
        }

        throw new \Error('pcntl_wexitstatus() is not available in this compiler build');
    }

    public static function wifsignaled(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifsignaled($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifsignaled($status);
        }

        throw new \Error('pcntl_wifsignaled() is not available in this compiler build');
    }

    public static function wifstopped(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifstopped($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifstopped($status);
        }

        throw new \Error('pcntl_wifstopped() is not available in this compiler build');
    }

    public static function wifcontinued(int $status): bool
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wifcontinued($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wifcontinued($status);
        }

        throw new \Error('pcntl_wifcontinued() is not available in this compiler build');
    }

    public static function wtermsig(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wtermsig($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wtermsig($status);
        }

        throw new \Error('pcntl_wtermsig() is not available in this compiler build');
    }

    public static function wstopsig(int $status): int
    {
        if (PcntlHostBridge::forkAvailable()) {
            return PcntlHostBridge::wstopsig($status);
        }
        if (PcntlLibcThinAbi::processAvailable()) {
            return PcntlLibcThinAbi::wstopsig($status);
        }

        throw new \Error('pcntl_wstopsig() is not available in this compiler build');
    }

    public static function priorityAvailable(): bool
    {
        return PcntlHostBridge::priorityAvailable() || PcntlLibcThinAbi::priorityAvailable();
    }

    /**
     * php-src pcntl_getpriority() — getpriority(2) (ext/pcntl/pcntl.c; #20046).
     *
     * @return int|false
     */
    public static function getpriority(?int $pid, int $who): int|false
    {
        if (!self::isValidPrioWho($who)) {
            throw new \ValueError(
                'pcntl_getpriority(): Argument #2 ($mode) must be one of PRIO_PGRP, PRIO_USER, or PRIO_PROCESS'
            );
        }
        if (PcntlHostBridge::priorityAvailable()) {
            $result = PcntlHostBridge::getpriority($pid, $who);
            if (false === $result) {
                self::syncLastErrorFromHost();
            }

            return $result;
        }
        if (PcntlLibcThinAbi::priorityAvailable()) {
            $errno = 0;
            $result = PcntlLibcThinAbi::getpriority($pid, $who, $errno);
            if (false === $result) {
                self::$lastError = $errno;
            }

            return $result;
        }

        throw new \Error('pcntl_getpriority() is not available in this compiler build');
    }

    /**
     * php-src pcntl_setpriority() — setpriority(2) (ext/pcntl/pcntl.c; #20046).
     */
    public static function setpriority(int $priority, ?int $pid, int $who): bool
    {
        if (!self::isValidPrioWho($who)) {
            throw new \ValueError(
                'pcntl_setpriority(): Argument #3 ($mode) must be one of PRIO_PGRP, PRIO_USER, or PRIO_PROCESS'
            );
        }
        if (PcntlHostBridge::priorityAvailable()) {
            $ok = PcntlHostBridge::setpriority($priority, $pid, $who);
            if (!$ok) {
                self::syncLastErrorFromHost();
            }

            return $ok;
        }
        if (PcntlLibcThinAbi::priorityAvailable()) {
            $errno = 0;
            $ok = PcntlLibcThinAbi::setpriority($priority, $pid, $who, $errno);
            if (!$ok) {
                self::$lastError = $errno;
            }

            return $ok;
        }

        throw new \Error('pcntl_setpriority() is not available in this compiler build');
    }

    /**
     * php-src pcntl_getcpuaffinity() — sched_getaffinity(2) (ext/pcntl/pcntl.c; #20510).
     *
     * @return list<int>|false
     */
    public static function getcpuaffinity(?int $pid): array|false
    {
        if (!PcntlLibcThinAbi::cpuAffinityAvailable()) {
            throw new \Error('pcntl_getcpuaffinity() is not available in this compiler build');
        }
        // php-src: null → 0 (current process without getpid syscall)
        $processId = null === $pid ? 0 : $pid;
        $errno = 0;
        $cpus = PcntlLibcThinAbi::getcpuaffinity($processId, $errno);
        if (false === $cpus) {
            self::$lastError = $errno;
            if (PcntlConstants::PCNTL_ESRCH === $errno) {
                throw new \ValueError(
                    \sprintf('pcntl_getcpuaffinity(): Argument #1 ($process_id) invalid process (%d)', $processId)
                );
            }
            if (PcntlConstants::PCNTL_EINVAL === $errno) {
                throw new \ValueError('invalid cpu affinity mask size');
            }
            if (PcntlConstants::PCNTL_EPERM === $errno) {
                \trigger_error(
                    'pcntl_getcpuaffinity(): Calling process not having the proper privileges',
                    \E_USER_WARNING
                );
            }

            return false;
        }

        return $cpus;
    }

    /**
     * php-src pcntl_setcpuaffinity() — sched_setaffinity(2) (ext/pcntl/pcntl.c; #20510).
     *
     * @param list<int> $cpuIds
     */
    public static function setcpuaffinity(?int $pid, array $cpuIds): bool
    {
        if (!PcntlLibcThinAbi::cpuAffinityAvailable()) {
            throw new \Error('pcntl_setcpuaffinity() is not available in this compiler build');
        }
        if ([] === $cpuIds) {
            throw new \ValueError('pcntl_setcpuaffinity(): Argument #2 ($cpu_ids) must not be empty');
        }
        $maxCpus = PcntlLibcThinAbi::configuredProcessorCount();
        foreach ($cpuIds as $cpu) {
            if ($cpu < 0 || $cpu >= $maxCpus) {
                throw new \ValueError(
                    \sprintf(
                        'pcntl_setcpuaffinity(): Argument #2 ($cpu_ids) cpu id must be between 0 and %d (%d)',
                        $maxCpus,
                        $cpu
                    )
                );
            }
        }
        $processId = null === $pid ? 0 : $pid;
        $errno = 0;
        $ok = PcntlLibcThinAbi::setcpuaffinity($processId, $cpuIds, $errno);
        if (!$ok) {
            self::$lastError = $errno;
            if (PcntlConstants::PCNTL_ESRCH === $errno) {
                throw new \ValueError(
                    \sprintf('pcntl_setcpuaffinity(): Argument #1 ($process_id) invalid process (%d)', $processId)
                );
            }
            if (PcntlConstants::PCNTL_EINVAL === $errno) {
                throw new \ValueError(
                    'pcntl_setcpuaffinity(): Argument #2 ($cpu_ids) invalid cpu affinity mask size or unmapped cpu id(s)'
                );
            }
            if (PcntlConstants::PCNTL_EPERM === $errno) {
                \trigger_error(
                    'pcntl_setcpuaffinity(): Calling process not having the proper privileges',
                    \E_USER_WARNING
                );
            }

            return false;
        }

        return true;
    }

    /**
     * php-src pcntl_getcpu() — sched_getcpu(3) (ext/pcntl/pcntl.c; #20510).
     */
    public static function getcpu(): int
    {
        if (!PcntlLibcThinAbi::cpuAffinityAvailable()) {
            throw new \Error('pcntl_getcpu() is not available in this compiler build');
        }

        return PcntlLibcThinAbi::getcpu();
    }

    private static function isValidPrioWho(int $who): bool
    {
        return PcntlConstants::PRIO_PROCESS === $who
            || PcntlConstants::PRIO_PGRP === $who
            || PcntlConstants::PRIO_USER === $who;
    }

    public static function hasHandler(int $signo): bool
    {
        return isset(self::$handlers[$signo]);
    }

    public static function markPending(int $signo): void
    {
        self::$pending[] = $signo;
    }

    public static function signal(int $signo, ?Variable $handler): bool
    {
        VmPcntlArg::validateSignal($signo, 'pcntl_signal');
        if (PcntlConstants::isUncatchable($signo)) {
            throw new \ValueError('Cannot catch SIGKILL or SIGSTOP');
        }
        if (null === $handler) {
            unset(self::$handlers[$signo], self::$dispositions[$signo]);

            return self::restoreOsHandler($signo);
        }
        $resolved = $handler->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $disposition = $resolved->toInt();
            if (PcntlConstants::SIG_DFL === $disposition || PcntlConstants::SIG_IGN === $disposition) {
                unset(self::$handlers[$signo]);
                self::$dispositions[$signo] = $disposition;

                return self::installOsDisposition($signo, $disposition);
            }
        }
        unset(self::$dispositions[$signo]);
        if (VmClosureCall::isClosure($resolved)) {
            $stored = new Variable();
            $stored->copyFrom($resolved);
            self::$handlers[$signo] = [
                'kind' => 'closure',
                'closure' => VmClosureCall::resolve($resolved),
                'source' => $stored,
            ];
        } else {
            $stored = new Variable();
            $stored->copyFrom($resolved);
            self::$handlers[$signo] = [
                'kind' => 'callable',
                'callable' => $stored,
            ];
        }

        return self::installOsHandler($signo);
    }

    public static function getHandler(int $signo): Variable
    {
        VmPcntlArg::validateSignal($signo, 'pcntl_signal_get_handler');
        $ret = new Variable();
        if (isset(self::$handlers[$signo])) {
            $handler = self::$handlers[$signo];
            if ('closure' === $handler['kind']) {
                $ret->copyFrom($handler['source']);

                return $ret;
            }
            $ret->copyFrom($handler['callable']);

            return $ret;
        }
        $ret->int(self::$dispositions[$signo] ?? PcntlConstants::SIG_DFL);

        return $ret;
    }

    public static function asyncSignals(?bool $enable): bool
    {
        if (PcntlHostBridge::available() && \function_exists('pcntl_async_signals')) {
            return PcntlHostBridge::asyncSignals($enable);
        }
        if (null === $enable) {
            return self::$asyncSignals;
        }
        self::$asyncSignals = $enable;

        return true;
    }

    public static function sigprocmask(int $mode, array $signals, ?Variable $oldOut): bool
    {
        foreach ($signals as $signo) {
            VmPcntlArg::validateSignal($signo, 'pcntl_sigprocmask');
        }
        $old = [];
        if (PcntlHostBridge::available() && \function_exists('pcntl_sigprocmask')) {
            $ok = PcntlHostBridge::sigprocmask($mode, $signals, $old);
            if (null !== $oldOut) {
                VmPcntlArg::writeSignalList($old, $oldOut);
            }
            self::$blockedSignals = $old;

            return $ok;
        }
        if (PcntlLibcThinAbi::sigprocmaskAvailable()) {
            $ok = PcntlLibcThinAbi::sigprocmask($mode, $signals, $old);
            if (null !== $oldOut) {
                VmPcntlArg::writeSignalList($old, $oldOut);
            }
            self::$blockedSignals = $old;

            return $ok;
        }
        $previous = self::$blockedSignals;
        self::$blockedSignals = self::applyLocalMask($mode, $signals, self::$blockedSignals);
        if (null !== $oldOut) {
            VmPcntlArg::writeSignalList($previous, $oldOut);
        }

        return true;
    }

    /**
     * @param array<string, int>|null $infoOut
     */
    public static function sigtimedwait(array $signals, ?Variable $infoOut, int $seconds, int $nanoseconds): int|false
    {
        foreach ($signals as $signo) {
            VmPcntlArg::validateSignal($signo, 'pcntl_sigtimedwait');
        }
        $info = [];
        if (PcntlHostBridge::available() && \function_exists('pcntl_sigtimedwait')) {
            $rc = PcntlHostBridge::sigtimedwait($signals, $info, $seconds, $nanoseconds);
            if (false !== $rc && null !== $infoOut) {
                self::writeSiginfo($info, $infoOut);
            }

            return $rc;
        }

        throw new \Error('pcntl_sigtimedwait() is not available in this compiler build');
    }

    /**
     * @param array<string, int>|null $infoOut
     */
    public static function sigwaitinfo(array $signals, ?Variable $infoOut): int|false
    {
        foreach ($signals as $signo) {
            VmPcntlArg::validateSignal($signo, 'pcntl_sigwaitinfo');
        }
        $info = [];
        if (PcntlHostBridge::available()) {
            $rc = PcntlHostBridge::sigwaitinfo($signals, $info);
            if (false !== $rc && null !== $infoOut) {
                self::writeSiginfo($info, $infoOut);
            }

            return $rc;
        }

        throw new \Error('pcntl_sigwaitinfo() is not available in this compiler build');
    }

    public static function waitid(int $idtype, int $id, ?Variable $infoOut, int $options): bool
    {
        if (PcntlLibcThinAbi::waitidAvailable()) {
            $info = [];
            $ok = PcntlLibcThinAbi::waitid($idtype, $id, $info, $options);
            if ($ok && null !== $infoOut) {
                self::writeSiginfo($info, $infoOut);
            }

            return $ok;
        }

        throw new \Error('pcntl_waitid() is not available in this compiler build');
    }

    public static function dispatch(Context $context): bool
    {
        if (!self::available()) {
            return false;
        }
        if (PcntlHostBridge::preferred()) {
            PcntlHostBridge::drainHostPending();
        }
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $signo) {
            if (!isset(self::$handlers[$signo])) {
                continue;
            }
            self::invokeHandler($context, $signo, self::$handlers[$signo]);
        }

        return true;
    }

    /**
     * @param array{kind: 'closure', closure: ClosureState, source: Variable}|array{kind: 'callable', callable: Variable} $handler
     */
    private static function invokeHandler(Context $context, int $signo, array $handler): void
    {
        $signoVar = new Variable();
        $signoVar->int($signo);
        if ('closure' === $handler['kind']) {
            VmClosureCall::invoke($context, $handler['closure'], $signoVar);

            return;
        }
        VmCallable::invoke($context, $handler['callable'], $signoVar);
    }

    private static function installOsHandler(int $signo): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::installHandler($signo);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::installHandler($signo);
        }

        return true;
    }

    private static function installOsDisposition(int $signo, int $disposition): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::installDisposition($signo, $disposition);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::installDisposition($signo, $disposition);
        }

        return true;
    }

    private static function restoreOsHandler(int $signo): bool
    {
        if (PcntlHostBridge::preferred()) {
            return PcntlHostBridge::restoreDefault($signo);
        }
        if (PcntlLibcThinAbi::supportsNativeDispatch()) {
            return PcntlLibcThinAbi::restoreDefault($signo);
        }

        return true;
    }

    /**
     * @param list<int> $signals
     * @param list<int> $current
     *
     * @return list<int>
     */
    private static function applyLocalMask(int $mode, array $signals, array $current): array
    {
        $set = [];
        foreach ($current as $signo) {
            $set[(int) $signo] = true;
        }
        foreach ($signals as $signo) {
            $signo = (int) $signo;
            if (PcntlConstants::SIG_BLOCK === $mode) {
                $set[$signo] = true;
            } elseif (PcntlConstants::SIG_UNBLOCK === $mode) {
                unset($set[$signo]);
            } elseif (PcntlConstants::SIG_SETMASK === $mode) {
                $set = [];
            }
        }
        if (PcntlConstants::SIG_SETMASK === $mode) {
            foreach ($signals as $signo) {
                $set[(int) $signo] = true;
            }
        }

        return \array_keys($set);
    }

    /**
     * @param array<string, int> $info
     */
    private static function writeSiginfo(array $info, Variable $out): void
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $var = new Variable();
            $var->int((int) $value);
            $ht->add((string) $key, $var);
        }
        $out->byRefTarget()->array($ht);
    }
}
