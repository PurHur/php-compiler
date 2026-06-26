<?php

declare(strict_types=1);

/**
 * VM process helpers — libc FFI when available (#5388, #7862, #8652, #8889).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

final class VmProcess
{
    /**
     * @return HashTable|false
     */
    public static function getrusage(int $who = 0)
    {
        $raw = VmGetrusageNative::getrusage($who);
        if (false === $raw) {
            return false;
        }

        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            if (\is_int($key)) {
                continue;
            }
            $slot = new Variable();
            $slot->int((int) $value);
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    /** proc_nice() — libc nice(3) via FFI (php-src basic_functions.c; #5181, #7862). */
    public static function proc_nice(int $priority): bool
    {
        return VmProcNiceNative::proc_nice($priority);
    }

    public static function isValidHandle(int $handle): bool
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return true;
        }

        return isset(self::$legacyHostHandles[$handle]);
    }

    /** @return resource|null */
    public static function lookupProcess(int $handle): mixed
    {
        return self::$legacyHostHandles[$handle] ?? null;
    }

    /**
     * proc_open() — spawn subprocess with pipe descriptors (php-src ext/standard/proc_open.c; #3131, #8652).
     *
     * @param string|list<string> $command
     * @param array<int, array{0: string, 1?: string}> $descriptorSpec
     * @param array<string, string>|null $env
     *
     * @return array{0: int, 1: array<int, int>}|false [processHandleId, pipeHandleIds by fd]
     */
    public static function procOpen(
        string|array $command,
        array $descriptorSpec,
        ?string $cwd = null,
        ?array $env = null,
    ): array|false {
        if (VmProcessProcOpenNative::available()) {
            if (\is_array($command)) {
                return VmProcessProcOpenNative::openArgv($command, $descriptorSpec, $cwd, $env);
            }

            return VmProcessProcOpenNative::open($command, $descriptorSpec, $cwd, $env);
        }

        return self::procOpenHost($command, $descriptorSpec, $cwd, $env);
    }

    /** proc_close() — wait for subprocess and return exit code (php-src ext/standard/proc_open.c; #3131). */
    public static function procClose(int $handle): int
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return VmProcessProcOpenNative::close($handle);
        }

        $proc = self::$legacyHostHandles[$handle] ?? null;
        if (null === $proc) {
            return -1;
        }
        unset(self::$legacyHostHandles[$handle]);
        $result = @\proc_close($proc);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /**
     * proc_get_status() — inspect child process (php-src ext/standard/proc_open.c; #3740).
     *
     * @return array<string, mixed>|false
     */
    public static function procGetStatus(int $handle): array|false
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return VmProcessProcOpenNative::getStatus($handle);
        }

        $proc = self::$legacyHostHandles[$handle] ?? null;
        if (null === $proc) {
            return false;
        }
        $status = @\proc_get_status($proc);
        if (!\is_array($status)) {
            return false;
        }

        return $status;
    }

    /** proc_terminate() — signal child process (php-src ext/standard/proc_open.c; #3740). */
    public static function procTerminate(int $handle, int $signal = 15): bool
    {
        if (VmProcessProcOpenNative::isValidHandle($handle)) {
            return VmProcessProcOpenNative::terminate($handle, $signal);
        }

        $proc = self::$legacyHostHandles[$handle] ?? null;
        if (null === $proc) {
            return false;
        }

        return @\proc_terminate($proc, $signal);
    }

    /**
     * stream_select() — multiplex stream handles (php-src ext/standard/streams.c; #3131).
     *
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     *
     * @deprecated Use {@see VmStreamSelect::multiplex()} (#9216)
     */
    public static function streamSelect(
        array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds,
    ): int|false {
        return VmStreamSelect::multiplex($read, $write, $except, $seconds, $microseconds);
    }

    /**
     * @deprecated Use {@see VmStreamSelect::pairsFromArray()} (#9216)
     *
     * @return list<StreamSelectPair>
     */
    public static function hostStreamsFromArray(Variable $arrayVar): array
    {
        return VmStreamSelect::pairsFromArray($arrayVar);
    }

    /**
     * @deprecated Use {@see VmStreamSelect::writeBackStreamArray()} (#9216)
     *
     * @param list<int> $readyHandles
     */
    public static function writeBackStreamArray(Variable $targetVar, array $readyHandles, \PHPCompiler\VM\Context $ctx): void
    {
        VmStreamSelect::writeBackStreamArray($targetVar, $readyHandles, $ctx);
    }

    /** @var array<int, resource> legacy host proc_open handles (array command or FFI unavailable) */
    private static array $legacyHostHandles = [];

    private static int $nextLegacyHandleId = 0;

    /**
     * @param string|list<string> $command
     * @param array<int, array{0: string, 1?: string}> $descriptorSpec
     * @param array<string, string>|null $env
     *
     * @return array{0: int, 1: array<int, int>}|false
     */
    private static function procOpenHost(
        string|array $command,
        array $descriptorSpec,
        ?string $cwd,
        ?array $env,
    ): array|false {
        $pipes = [];
        $proc = @\proc_open($command, $descriptorSpec, $pipes, $cwd, $env);
        if (!\is_resource($proc)) {
            return false;
        }
        $procId = ++self::$nextLegacyHandleId;
        self::$legacyHostHandles[$procId] = $proc;
        $pipeHandles = [];
        foreach ($pipes as $fd => $hostPipe) {
            if (!\is_resource($hostPipe)) {
                continue;
            }
            $handleId = VmFs::adoptStreamResource($hostPipe, 'proc_open pipe');
            if (false === $handleId) {
                continue;
            }
            $pipeHandles[(int) $fd] = $handleId;
        }

        return [$procId, $pipeHandles];
    }
}
