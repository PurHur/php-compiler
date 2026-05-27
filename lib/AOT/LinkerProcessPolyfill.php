<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Zend bootstrap polyfill for process capture during bin/compile.php link (#2779).
 *
 * Compiled AOT uses ext/standard/phpc_run_command Internal lowering instead.
 */
final class LinkerProcessPolyfill
{
    /**
     * @param array<string, string>|null $env
     *
     * @return array{code:int,stdout:string,stderr:string}|null
     */
    public static function run(string $command, ?array $env = null): ?array
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open($command, $descriptor, $pipes, null, $env);
        if (!\is_resource($proc)) {
            return null;
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);

        return [
            'code' => (int) $code,
            'stdout' => false === $stdout ? '' : $stdout,
            'stderr' => false === $stderr ? '' : $stderr,
        ];
    }
}
