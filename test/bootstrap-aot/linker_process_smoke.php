<?php

declare(strict_types=1);

/** Bootstrap AOT lint fixture for Linker process builtins (#2779). */
function bootstrap_linker_process_smoke(): int
{
    $quoted = escapeshellarg('hello world');
    if ("'hello world'" !== $quoted) {
        return 1;
    }
    $path = trim((string) shell_exec('command -v echo 2>/dev/null'));
    if ('' === $path) {
        return 2;
    }
    $captured = phpc_run_command('echo linker-process-smoke');
    if (!is_array($captured)) {
        return 3;
    }
    if (0 !== (int) ($captured['code'] ?? 1)) {
        return 4;
    }
    $stdout = $captured['stdout'] ?? '';
    if (!is_string($stdout) || !str_contains($stdout, 'linker-process-smoke')) {
        return 5;
    }

    return 0;
}

exit(bootstrap_linker_process_smoke());
