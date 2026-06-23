<?php
declare(strict_types=1);
function backtrace_limit_probe(): void
{
    echo count(debug_backtrace(limit: 1)), "\n";
}
backtrace_limit_probe();
