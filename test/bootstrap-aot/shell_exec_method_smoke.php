<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: shell_exec() inside a class static method (lib/AOT/Linker.php pattern, #2779).
 */
final class ShellExecMethodSmoke
{
    public static function run(): int
    {
        $out = shell_exec('echo linker-shell-exec-smoke');

        return is_string($out) && str_contains($out, 'linker-shell-exec-smoke') ? 0 : 1;
    }
}

function shell_exec_method_smoke(): int
{
    return ShellExecMethodSmoke::run();
}
