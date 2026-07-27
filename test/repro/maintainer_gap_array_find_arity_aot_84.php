<?php

/**
 * Issue #23875 — AOT: array_find rejects 3rd arg (php-src exactly 2).
 * Uncaught path: native fatal ArgumentCountError (multi try/catch still CFGs under AOT).
 */

function phpc_find_eq2($v)
{
    return $v === 2;
}

array_find([1, 2, 3], 'phpc_find_eq2', true);
echo "reached\n";
