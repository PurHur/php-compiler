<?php

declare(strict_types=1);

function inner(): void
{
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo 'line=', $bt[0]['line'], "\n";
}

function outer(): void
{
    inner();
}

outer();
