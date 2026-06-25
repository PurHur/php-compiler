<?php
// Zend: uninitialized $pipes by-ref out-param binds without E_WARNING (#11435).
error_reporting(E_ALL);
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});
proc_open('echo hi', [1 => ['pipe', 'w']], $pipes);
$pipesOk = isset($pipes[1]) && is_resource($pipes[1]);
if (0 === $warnings && $pipesOk) {
    echo "proc_open_pipes_byref_ok\n";
} else {
    echo 'proc_open_pipes_byref_fail warnings='.$warnings.' pipes_ok='.($pipesOk ? 'yes' : 'no')."\n";
}
