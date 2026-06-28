<?php

declare(strict_types=1);

/**
 * Issue #12961 — call_user_func_array() forwards by-ref arguments.
 */

function inc_by_ref(&$x): void
{
    $x++;
}

$a = 1;
call_user_func_array('inc_by_ref', [&$a]);
echo ($a === 2) ? "ok\n" : "fail: expected a=2, got {$a}\n";
