<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Narrow host proc_open bridge for phpc_run_command env replacement (#8633 follow-up).
 *
 * Isolated so runtime-shrink tests can assert the main VM path avoids host delegation.
 */
final class VmPhpcRunCommandHost
{
    /**
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    public static function runWithEnv(string $command, array $env): ?array
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @\proc_open($command, $descriptor, $pipes, null, $env);
        if (!\is_resource($proc)) {
            return null;
        }
        @\fclose($pipes[0]);
        $stdout = @\stream_get_contents($pipes[1]);
        $stderr = @\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $code = @\proc_close($proc);
        if (false === $code) {
            return null;
        }

        return [
            'code' => (int) $code,
            'stdout' => false === $stdout ? '' : $stdout,
            'stderr' => false === $stderr ? '' : $stderr,
        ];
    }
}
