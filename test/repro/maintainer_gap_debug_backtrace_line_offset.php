<?php

declare(strict_types=1);

function inner(): void
{
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo $t[0]['line'], "\n";
}

inner();

exit(0);
