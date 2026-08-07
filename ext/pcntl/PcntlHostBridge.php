<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

/**
 * Host Zend pcntl bridge when FFI::callback is unavailable (PHP < 8.3; issue #6680).
 *
 * Registers a host-level handler that only enqueues signo for {@see VmPcntl::dispatch};
 * user callables stay in VM PHP state.
 *
 * php-src: ext/pcntl/pcntl.c — pending queue + dispatch in request context.
 */
final class PcntlHostBridge
{
    public static function available(): bool
    {
        return \function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch');
    }

    public static function preferred(): bool
    {
        return self::available() && !self::ffiCallbackAvailable();
    }

    public static function installHandler(int $signo): bool
    {
        if (!self::available()) {
            return false;
        }

        return \pcntl_signal($signo, static function (int $sig): void {
            VmPcntl::markPending($sig);
        }, true);
    }

    public static function installDisposition(int $signo, int $disposition): bool
    {
        if (!self::available()) {
            return false;
        }
        $handler = PcntlConstants::SIG_IGN === $disposition ? \SIG_IGN : \SIG_DFL;

        return \pcntl_signal($signo, $handler, true);
    }

    public static function restoreDefault(int $signo): bool
    {
        if (!self::available()) {
            return false;
        }

        return \pcntl_signal($signo, \SIG_DFL, true);
    }

    public static function drainHostPending(): void
    {
        if (!self::available()) {
            return;
        }
        \pcntl_signal_dispatch();
    }

    public static function asyncSignals(?bool $enable): bool
    {
        if (!\function_exists('pcntl_async_signals')) {
            return false;
        }
        if (null === $enable) {
            return \pcntl_async_signals();
        }

        return \pcntl_async_signals($enable);
    }

    /**
     * @param list<int> $signals
     * @param list<int>|null $old
     */
    public static function sigprocmask(int $mode, array $signals, ?array &$old = null): bool
    {
        if (!\function_exists('pcntl_sigprocmask')) {
            return false;
        }

        return \pcntl_sigprocmask($mode, $signals, $old);
    }

    /**
     * @param list<int> $signals
     * @param array<string, int>|null $info
     */
    public static function sigtimedwait(array $signals, ?array &$info, int $seconds, int $nanoseconds): int|false
    {
        if (!\function_exists('pcntl_sigtimedwait')) {
            return false;
        }

        return \pcntl_sigtimedwait($signals, $info, $seconds, $nanoseconds);
    }

    public static function forkAvailable(): bool
    {
        return \function_exists('pcntl_fork');
    }

    public static function fork(): int
    {
        return (int) \pcntl_fork();
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
        if ($captureRusage) {
            $ru = [];
            $rc = (int) \pcntl_waitpid($pid, $status, $options, $ru);
            $resourceUsage = $ru;

            return $rc;
        }

        return (int) \pcntl_waitpid($pid, $status, $options);
    }

    public static function alarmAvailable(): bool
    {
        return \function_exists('pcntl_alarm');
    }

    public static function alarm(int $seconds): int
    {
        return (int) \pcntl_alarm($seconds);
    }

    public static function execAvailable(): bool
    {
        return \function_exists('pcntl_exec');
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public static function exec(string $path, array $args, array $env): bool
    {
        // Suppress host Warning on failure so @pcntl_exec in user code matches Zend
        // (errno message would otherwise leak from this bridge call site).
        if ([] === $env) {
            return (bool) @\pcntl_exec($path, $args);
        }

        return (bool) @\pcntl_exec($path, $args, $env);
    }

    public static function wifexited(int $status): bool
    {
        return \pcntl_wifexited($status);
    }

    public static function wexitstatus(int $status): int
    {
        return (int) \pcntl_wexitstatus($status);
    }

    public static function wifsignaled(int $status): bool
    {
        return \pcntl_wifsignaled($status);
    }

    public static function wifstopped(int $status): bool
    {
        return \pcntl_wifstopped($status);
    }

    public static function wifcontinued(int $status): bool
    {
        if (\function_exists('pcntl_wifcontinued')) {
            return \pcntl_wifcontinued($status);
        }

        return (0xffff === ($status & 0xffff));
    }

    /**
     * @param list<int> $signals
     * @param array<string, int>|null $info
     */
    public static function sigwaitinfo(array $signals, ?array &$info = null): int|false
    {
        if (!\function_exists('pcntl_sigwaitinfo')) {
            return false;
        }

        return \pcntl_sigwaitinfo($signals, $info);
    }

    public static function wtermsig(int $status): int
    {
        return (int) \pcntl_wtermsig($status);
    }

    public static function wstopsig(int $status): int
    {
        return (int) \pcntl_wstopsig($status);
    }

    public static function priorityAvailable(): bool
    {
        return \function_exists('pcntl_getpriority') && \function_exists('pcntl_setpriority');
    }

    public static function getpriority(?int $pid, int $who): int|false
    {
        if (null === $pid) {
            return @\pcntl_getpriority(null, $who);
        }

        return @\pcntl_getpriority($pid, $who);
    }

    public static function setpriority(int $priority, ?int $pid, int $who): bool
    {
        if (null === $pid) {
            return (bool) @\pcntl_setpriority($priority, null, $who);
        }

        return (bool) @\pcntl_setpriority($priority, $pid, $who);
    }

    public static function strerrorAvailable(): bool
    {
        return \function_exists('pcntl_strerror');
    }

    public static function strerror(int $error): string
    {
        return (string) \pcntl_strerror($error);
    }

    public static function unshareAvailable(): bool
    {
        return \function_exists('pcntl_unshare');
    }

    public static function unshare(int $flags): bool
    {
        return (bool) @\pcntl_unshare($flags);
    }

    public static function setnsAvailable(): bool
    {
        return \function_exists('pcntl_setns');
    }

    public static function setns(?int $pid, int $nstype): bool
    {
        if (null === $pid) {
            return (bool) @\pcntl_setns(null, $nstype);
        }

        return (bool) @\pcntl_setns($pid, $nstype);
    }

    private static function ffiCallbackAvailable(): bool
    {
        return \class_exists(\FFI::class, false) && \method_exists(\FFI::class, 'callback');
    }
}
