<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * proc_open/close/status/terminate/is_process_resource for compiled JIT/AOT (#9408, #12958).
 *
 * SSOT: {@see ProcessSlotJitHelper}, {@see VmProcessProcOpenNative}
 * php-src: ext/standard/proc_open.c
 */
final class ProcessOpenJitHelper
{
    public const PROCESS_HANDLE_BASE = 0x20000000;

    private const MAX_SLOTS = 64;

    /** @var array<int, array{0: string, 1?: string}> */
    private const DEFAULT_PIPE_DESCRIPTOR = [
        0 => ['pipe', 'w'],
        1 => ['pipe', 'r'],
        2 => ['pipe', 'r'],
    ];

    /** @return int ABI for __compiler_proc_open (handle or -1) */
    public static function procOpenArgv(?string $command, ?HashTable $pipesHt): int
    {
        if (null === $command || '' === $command || null === $pipesHt) {
            return -1;
        }

        $result = VmProcessProcOpenNative::open($command, self::DEFAULT_PIPE_DESCRIPTOR);
        if (false === $result) {
            return -1;
        }

        [$handle, $pipeHandles] = $result;
        foreach ($pipeHandles as $fd => $streamHandle) {
            $slot = new Variable();
            $slot->int($streamHandle);
            $pipesHt->addIndex($fd, $slot);
        }

        return $handle;
    }

    /** @return 0|1 ABI for __compiler_is_process_resource */
    public static function isProcessResourceArgv(int $handle): int
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return 1;
        }
        $slot = self::slotFromHandle($handle);
        if (null === $slot) {
            return 0;
        }

        return ProcessSlotJitHelper::isActive($slot) ? 1 : 0;
    }

    /** @return int ABI for __compiler_proc_close (exit code or -1) */
    public static function procCloseArgv(int $handle): int
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return VmProcessProcOpenNative::close($handle);
        }
        $slot = self::slotFromHandle($handle);
        if (null === $slot) {
            return -1;
        }

        return ProcessSlotJitHelper::close($slot);
    }

    /** @return HashTable|null ABI for __compiler_proc_get_status */
    public static function procGetStatusArgv(int $handle): ?HashTable
    {
        $status = false;
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            $status = VmProcessProcOpenNative::getStatus($handle);
        } else {
            $slot = self::slotFromHandle($handle);
            if (null !== $slot) {
                $status = ProcessSlotJitHelper::getStatus($slot);
            }
        }
        if (false === $status) {
            return null;
        }

        $ht = new HashTable();
        foreach ($status as $key => $value) {
            $slot = new Variable();
            if (\is_bool($value)) {
                $slot->bool($value);
            } elseif (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } else {
                $slot->null();
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    /** @return 0|1 ABI for __compiler_proc_terminate */
    public static function procTerminateArgv(int $handle, int $signal): int
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return VmProcessProcOpenNative::terminate($handle, $signal) ? 1 : 0;
        }
        $slot = self::slotFromHandle($handle);
        if (null === $slot) {
            return 0;
        }

        return ProcessSlotJitHelper::terminate($slot, $signal) ? 1 : 0;
    }

    /** Register embed slot after LLVM proc_open parent path (#9408). */
    public static function registerSlotArgv(int $slot, int $pid, string $command): void
    {
        ProcessSlotJitHelper::register($slot, $pid, $command);
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        ProcessSlotJitHelper::resetForTest();
    }

    private static function slotFromHandle(int $handle): ?int
    {
        $slot = $handle - self::PROCESS_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_SLOTS) {
            return null;
        }

        return $slot;
    }
}
