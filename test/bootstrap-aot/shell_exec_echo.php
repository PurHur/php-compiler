<?php

declare(strict_types=1);

/** Bootstrap AOT lint fixture for shell_exec() lowering (inventory blocker batch 2). */
function bootstrap_shell_exec(): int
{
    $out = shell_exec('echo bootstrap');
    if (!is_string($out)) {
        return 1;
    }

    return str_contains($out, 'bootstrap') ? 0 : 2;
}
