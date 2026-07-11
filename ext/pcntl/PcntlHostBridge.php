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

    private static function ffiCallbackAvailable(): bool
    {
        return \class_exists(\FFI::class, false) && \method_exists(\FFI::class, 'callback');
    }
}
