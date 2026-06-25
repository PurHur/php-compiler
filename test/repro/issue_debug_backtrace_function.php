<?php

declare(strict_types=1);

function bt(): void
{
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    echo 'count='.count($t)."\n";
    echo 'fn='.var_export($t[0]['function'] ?? null, true)."\n";
    echo 'line='.($t[0]['line'] ?? 0)."\n";
}

bt();
