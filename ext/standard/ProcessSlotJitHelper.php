<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process slot table for compiled JIT/AOT embed modules (#9408, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\ProcessOpenStandaloneLlvm} phpc_process_* globals
 * for proc_close/status/terminate without duplicating LLVM emitters.
 * php-src: ext/standard/proc_open.c
 */
final class ProcessSlotJitHelper
{
    private const MAX_SLOTS = 64;

    private const EXIT_127 = 127;

    private const WNOHANG = 1;

    /** @var array<int, array{pid: int, command: string, statusKnown: bool, status: int, active: bool}> */
    private static array $slots = [];

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function register(int $slot, int $pid, string $command): void
    {
        if ($slot < 0 || $slot >= self::MAX_SLOTS) {
            return;
        }
        self::$slots[$slot] = [
            'pid' => $pid,
            'command' => $command,
            'statusKnown' => false,
            'status' => 0,
            'active' => true,
        ];
    }

    public static function isActive(int $slot): bool
    {
        return isset(self::$slots[$slot]) && self::$slots[$slot]['active'];
    }

    public static function close(int $slot): int
    {
        $entry = self::$slots[$slot] ?? null;
        if (null === $entry || !$entry['active']) {
            return -1;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $entry['active'] = false;
        self::$slots[$slot] = $entry;

        if ($entry['statusKnown']) {
            $status = $entry['status'];
            unset(self::$slots[$slot]);

            return self::exitCodeFromStatus($status);
        }

        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($entry['pid'], \FFI::addr($status), 0);
            unset(self::$slots[$slot]);
            if (-1 === $waitRc) {
                return -1;
            }

            return self::exitCodeFromStatus((int) $status->cdata);
        } catch (\Throwable) {
            unset(self::$slots[$slot]);

            return -1;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getStatus(int $slot): array|false
    {
        $entry = self::$slots[$slot] ?? null;
        if (null === $entry || !$entry['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $running = true;
        $statusVal = 0;

        if ($entry['statusKnown']) {
            $statusVal = $entry['status'];
            $running = false;
        } else {
            try {
                $status = $ffi->new('int');
                $waitRc = (int) $ffi->waitpid($entry['pid'], \FFI::addr($status), self::WNOHANG);
                if ($waitRc > 0) {
                    $statusVal = (int) $status->cdata;
                    $entry['status'] = $statusVal;
                    $entry['statusKnown'] = true;
                    self::$slots[$slot] = $entry;
                    $running = false;
                } elseif (-1 === $waitRc) {
                    $running = 0 === (int) $ffi->kill($entry['pid'], 0);
                }
            } catch (\Throwable) {
                return false;
            }
        }

        $lowByte = $statusVal & 0xff;
        $exited = 0 === $lowByte;
        $stopped = 0x7f === $lowByte;
        $signaled = $lowByte > 0 && !$stopped;
        $signals = VmProcessProcOpenNative::termsigStopsigFromWaitStatus($statusVal);

        return [
            'command' => $entry['command'],
            'pid' => $entry['pid'],
            'running' => $running,
            'exitcode' => $running ? -1 : ($exited ? (($statusVal >> 8) & 0xff) : -1),
            'signaled' => $signaled,
            'stopped' => $stopped,
            'termsig' => $signals['termsig'],
            'stopsig' => $signals['stopsig'],
        ];
    }

    public static function terminate(int $slot, int $signal = 15): bool
    {
        $entry = self::$slots[$slot] ?? null;
        if (null === $entry || !$entry['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->kill($entry['pid'], $signal);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$slots = [];
    }

    private static function exitCodeFromStatus(int $statusVal): int
    {
        if (0 === ($statusVal & 0xff)) {
            return ($statusVal >> 8) & 0xff;
        }

        return self::EXIT_127;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        self::$ffi = VmProcessProcOpenNative::sharedFfi();
        if (null === self::$ffi) {
            self::$ffiUnavailable = true;
        }

        return self::$ffi;
    }
}
