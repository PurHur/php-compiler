<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM popen/pclose without libc FFI (#8951, pairs {@see VmPopenNative}).
 *
 * Bootstrap path when FFI is disabled: host proc_open under Zend VM, adopted into VmFs handles.
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(popen), PHP_FUNCTION(pclose)
 */
final class VmPopenPure
{
    /** @var array<int, resource> proc handles awaiting pclose */
    private static array $procs = [];

    private static int $nextToken = 1;

    public static function available(): bool
    {
        return \function_exists('proc_open');
    }

    /**
     * @return array{handle: int, file: int}|false file is a pure-path pclose token
     */
    public static function open(string $command, string $mode): array|false
    {
        if (str_contains($command, "\0")) {
            return false;
        }

        $parsed = self::modeToDescriptorSpec($mode);
        if (null === $parsed) {
            return false;
        }
        [$descriptorSpec, $exposeFd] = $parsed;

        $pipes = [];
        $proc = @\proc_open($command, $descriptorSpec, $pipes, null, null);
        if (!\is_resource($proc)) {
            return false;
        }

        $hostPipe = $pipes[$exposeFd] ?? null;
        if (!\is_resource($hostPipe)) {
            self::closeUnusedPipes($pipes);
            @\proc_close($proc);

            return false;
        }

        foreach ($pipes as $fd => $pipe) {
            if ((int) $fd !== $exposeFd && \is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        $uri = 'popen://'.$command;
        $handle = VmFs::adoptStreamResource($hostPipe, $uri);
        if (false === $handle) {
            @\fclose($hostPipe);
            @\proc_close($proc);

            return false;
        }

        $token = self::$nextToken++;
        self::$procs[$token] = $proc;

        return ['handle' => $handle, 'file' => $token];
    }

    public static function pclose(int $token): int
    {
        $proc = self::$procs[$token] ?? null;
        unset(self::$procs[$token]);
        if (!\is_resource($proc)) {
            return -1;
        }

        $status = @\proc_close($proc);

        return false === $status ? -1 : (int) $status;
    }

    /**
     * @return array{0: array<int, array{0: string, 1?: string}>, 1: int}|null
     */
    private static function modeToDescriptorSpec(string $mode): ?array
    {
        $read = str_contains($mode, 'r');
        $write = str_contains($mode, 'w');
        $append = str_contains($mode, 'a');
        $plus = str_contains($mode, '+');

        if ($plus) {
            if ($append) {
                return [
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['file', 'php://stderr', 'w'],
                    ],
                    1,
                ];
            }
            if ($write) {
                return [
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['file', 'php://stderr', 'w'],
                    ],
                    1,
                ];
            }

            return [
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['file', 'php://stderr', 'w'],
                ],
                1,
            ];
        }

        if ($write || $append) {
            return [
                [
                    0 => ['pipe', 'r'],
                    2 => ['file', 'php://stderr', 'w'],
                ],
                0,
            ];
        }
        if ($read) {
            return [
                [
                    1 => ['pipe', 'w'],
                    2 => ['file', 'php://stderr', 'w'],
                ],
                1,
            ];
        }

        return null;
    }

    /**
     * @param array<int, resource> $pipes
     */
    private static function closeUnusedPipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
    }
}
