--TEST--
stdlib proc_open() uninitialized $pipes by-ref — no E_WARNING (#11435, ext/standard/proc_open.c)
--FILE--
<?php
error_reporting(E_ALL);
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});
proc_open('echo ok', [1 => ['pipe', 'w']], $pipes);
echo $warnings, "\n";
echo isset($pipes[1]) && is_resource($pipes[1]) ? 'pipes_ok' : 'pipes_bad', "\n";
--EXPECT--
0
pipes_ok
