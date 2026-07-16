<?php

declare(strict_types=1);

/**
 * Repro for #19617 — func_get_args() inside a user function with zero explicit args.
 */
function f($a = null)
{
    var_export(func_get_args());
    echo PHP_EOL;
}
f();
f(1, 2);
