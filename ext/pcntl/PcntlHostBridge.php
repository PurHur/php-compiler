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

    public static function waitpid(int $pid, int &$status, int $options): int
    {
        return (int) \pcntl_waitpid($pid, $status, $options);
    }

    public static function wifexited(int $status): bool
    {
        return \pcntl_wifexited($status);
    }

    public static function wexitstatus(int $status): int
    {
        return (int) \pcntl_wexitstatus($status);
    }

    private static function ffiCallbackAvailable(): bool
    {
        return \class_exists(\FFI::class, false) && \method_exists(\FFI::class, 'callback');
    }
}
