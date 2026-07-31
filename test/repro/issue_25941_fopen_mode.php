<?php
/**
 * Issue #25941 — fopen empty/junk/null mode: false + Zend invalid-mode warning (VM + AOT).
 * Untyped handler — typed errno int64 is not supported for AOT JIT callbacks.
 */
error_reporting(E_ALL);
$path = sys_get_temp_dir().'/phpc_fm3_'.getmypid();
file_put_contents($path, 'x');

function issue_25941_fopen_mode_warn($n, $s)
{
    echo 'WARN:', $s, "\n";

    return true;
}
set_error_handler('issue_25941_fopen_mode_warn');

foreach (['', 'q', null] as $mode) {
    echo 'MODE=', var_export($mode, true), "\n";
    $h = fopen($path, $mode);
    echo 'type=', gettype($h), ' ===false=', var_export($h === false, true), "\n";
}
@unlink($path);
