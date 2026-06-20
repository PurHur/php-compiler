<?php

declare(strict_types=1);

/**
 * Parallel compile subprocess pool for bootstrap helpers.
 *
 * Set PHP_COMPILER_COMPILE_JOBS=N (default 1) to fan out independent bin/compile.php
 * subprocesses. RAM use scales roughly with jobs × PHP_COMPILER_MEMORY_LIMIT.
 */

function php_compiler_compile_jobs(): int
{
    $raw = getenv('PHP_COMPILER_COMPILE_JOBS');
    if (false === $raw || '' === $raw) {
        return 1;
    }
    $jobs = (int) $raw;
    if ($jobs < 1) {
        return 1;
    }

    return min($jobs, php_compiler_compile_jobs_cap());
}

function php_compiler_compile_jobs_cap(): int
{
    $detected = php_compiler_detect_cpu_count();

    return null === $detected ? 16 : max(1, min(16, $detected));
}

function php_compiler_detect_cpu_count(): ?int
{
    if (is_readable('/proc/cpuinfo')) {
        $count = 0;
        foreach (file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'processor')) {
                ++$count;
            }
        }
        if ($count > 0) {
            return $count;
        }
    }
    if (function_exists('shell_exec')) {
        $out = shell_exec('nproc 2>/dev/null');
        if (is_string($out) && '' !== trim($out)) {
            return max(1, (int) trim($out));
        }
    }

    return null;
}

/**
 * @param array<string, string>|null $env
 *
 * @return array{exit: int, output: string}
 */
function php_compiler_run_command(string $cmd, ?string $cwd = null, ?array $env = null): array
{
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procEnv = null;
    if (null !== $env) {
        $base = getenv();
        if (!is_array($base)) {
            $base = [];
        }
        $procEnv = array_merge($base, $env);
    }
    $proc = proc_open(
        $cmd,
        $descriptor,
        $pipes,
        is_string($cwd) && '' !== $cwd ? $cwd : null,
        $procEnv
    );
    if (!is_resource($proc)) {
        return ['exit' => 127, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return ['exit' => (int) $exit, 'output' => $output];
}

/**
 * @param list<array{id: string, cmd: string, cwd?: string, env?: array<string, string>}> $tasks
 *
 * @return array<string, array{exit: int, output: string}>
 */
function php_compiler_run_parallel_commands(array $tasks, ?int $jobs = null): array
{
    if ([] === $tasks) {
        return [];
    }
    $jobs = $jobs ?? php_compiler_compile_jobs();
    if ($jobs <= 1 || count($tasks) <= 1) {
        $results = [];
        foreach ($tasks as $task) {
            $results[$task['id']] = php_compiler_run_command(
                $task['cmd'],
                $task['cwd'] ?? null,
                $task['env'] ?? null
            );
        }

        return $results;
    }

    $results = [];
    $pending = array_values($tasks);
    /** @var array<string, array{proc: resource, pipes: array{0: resource, 1: resource, 2: resource}, output: string}> $active */
    $active = [];
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $reap = static function (string $id, array $status) use (&$active, &$results): void {
        if (!isset($active[$id])) {
            return;
        }
        $state = $active[$id];
        stream_set_blocking($state['pipes'][1], true);
        stream_set_blocking($state['pipes'][2], true);
        $output = $state['output']
            .(string) stream_get_contents($state['pipes'][1])
            .(string) stream_get_contents($state['pipes'][2]);
        fclose($state['pipes'][1]);
        fclose($state['pipes'][2]);
        proc_close($state['proc']);
        $exit = (int) ($status['exitcode'] ?? 1);
        if (-1 === $exit && !empty($status['signaled'])) {
            $exit = 128 + (int) ($status['termsig'] ?? 1);
        }
        if (-1 === $exit) {
            $exit = 1;
        }
        $results[$id] = ['exit' => $exit, 'output' => $output];
        unset($active[$id]);
    };

    $spawn = static function (array $task) use (&$active, &$results, $descriptor): void {
        $cwd = $task['cwd'] ?? null;
        $env = $task['env'] ?? null;
        $procEnv = null;
        if (null !== $env) {
            $base = getenv();
            if (!is_array($base)) {
                $base = [];
            }
            $procEnv = array_merge($base, $env);
        }
        $proc = proc_open(
            $task['cmd'],
            $descriptor,
            $pipes,
            is_string($cwd) && '' !== $cwd ? $cwd : null,
            $procEnv
        );
        if (!is_resource($proc)) {
            $results[$task['id']] = ['exit' => 127, 'output' => 'proc_open failed'];

            return;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $active[$task['id']] = [
            'proc' => $proc,
            'pipes' => $pipes,
            'output' => '',
        ];
    };

    while ([] !== $pending || [] !== $active) {
        while (count($active) < $jobs && [] !== $pending) {
            $spawn(array_shift($pending));
        }
        if ([] === $active) {
            break;
        }
        $read = [];
        foreach ($active as $state) {
            $read[] = $state['pipes'][1];
            $read[] = $state['pipes'][2];
        }
        if ([] !== $read) {
            stream_select($read, $write, $except, 1);
        }
        foreach (array_keys($active) as $id) {
            $state = $active[$id];
            foreach ([1, 2] as $idx) {
                $chunk = stream_get_contents($state['pipes'][$idx]);
                if (is_string($chunk) && '' !== $chunk) {
                    $active[$id]['output'] .= $chunk;
                }
            }
            $status = proc_get_status($state['proc']);
            if (!$status['running']) {
                $reap($id, $status);
            }
        }
    }

    return $results;
}
