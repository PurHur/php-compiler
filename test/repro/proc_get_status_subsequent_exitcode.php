<?php
/**
 * Repro for #23722 — subsequent proc_get_status exitcode must be -1 after first post-exit read.
 * php-src: ext/standard/proc_open.c
 */
$d = [1 => ['pipe', 'w']];
$p = proc_open('true', $d, $pipes);
fclose($pipes[1]);
while (($s = proc_get_status($p))['running']) {
    usleep(5000);
}
echo 'first=', $s['exitcode'], "\n";
echo 'second=', proc_get_status($p)['exitcode'], "\n";
echo 'third=', proc_get_status($p)['exitcode'], "\n";
echo 'running=', proc_get_status($p)['running'] ? '1' : '0', "\n";
proc_close($p);
