<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process slot table for compiled JIT/AOT embed modules (#9408, php-in-PHP).
 *
 * Fallback slot table when handles use {@see ProcessOpenJitHelper::PROCESS_HANDLE_BASE}.
 * php-src: ext/standard/proc_open.c
 */
final class ProcessSlotJitHelper
{
    private const MAX_SLOTS = 64;

    private const EXIT_127 = 127;

    private const WNOHANG = 1;

    /** Linux SIGCONT — wake SIGSTOP'd fork children before terminate (#25195). */
    private const SIGCONT = 18;

    /** @var array<int, array{pid: int, command: string, statusKnown: bool, status: int, active: bool, pendingSignals?: list<int>}> */
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
            return -1;
        }

        try {
            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($entry['pid'], \FFI::addr($status), 0);
            if (-1 === $waitRc) {
                unset(self::$slots[$slot]);

                return -1;
            }
            $entry['statusKnown'] = true;
            $entry['status'] = (int) $status->cdata;
            self::$slots[$slot] = $entry;

            return self::exitCodeFromStatus($entry['status']);
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
        if (null === $entry) {
            return false;
        }
        if (!$entry['active']) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        // php-src 8.2: already-reaped waitpid → ECHILD: exitcode=-1 on later calls (#23722).
        if ($entry['statusKnown']) {
            $pendingSignals = VmProcessProcOpenNative::resolvePendingSignals($entry, false, false, 0);
            self::$slots[$slot] = $entry;

            return VmProcessProcOpenNative::buildProcStatusArray(
                $entry['command'],
                $entry['pid'],
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

        $status = VmProcessProcOpenNative::computeProcGetStatusFromActiveSlot($entry, $ffi);
        self::$slots[$slot] = $entry;

        return $status;
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

        $pid = $entry['pid'];
        // Match VmProcessProcOpenNative::terminate — SIGCONT then signal (#25195).
        if ($pid > 0) {
            try {
                $ffi->kill($pid, self::SIGCONT);
            } catch (\Throwable) {
            }
        }

        try {
            return 0 === (int) $ffi->kill($pid, $signal);
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
        $lowByte = $statusVal & 0xff;
        if (0 === $lowByte) {
            return ($statusVal >> 8) & 0xff;
        }
        if (0x7f === $lowByte) {
            return -1;
        }

        return $lowByte;
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
