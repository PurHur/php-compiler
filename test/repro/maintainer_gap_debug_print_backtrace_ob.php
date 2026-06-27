<?php
/**
 * Repro #12743 — debug_print_backtrace() must write into active output buffer.
 */
function repro(): void
{
    ob_start();
    debug_print_backtrace();
    $len = strlen(ob_get_clean());
    var_export($len > 0);
}
repro();
