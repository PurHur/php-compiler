<?php

declare(strict_types=1);

/**
 * Linker-style shell_exec in a static method; entry function named `main` (#2779).
 */
final class LinkerShellExecProbe
{
    public static function resolveEcho(): int
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg('echo').' 2>/dev/null'));

        return '' !== $path ? 0 : 1;
    }
}

function main(): int
{
    return LinkerShellExecProbe::resolveEcho();
}
