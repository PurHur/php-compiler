--TEST--
stdlib proc_get_status() includes termsig and stopsig keys (#12142, ext/standard/proc_open.c)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$proc = proc_open('echo ok', $desc, $pipes);
$st = proc_get_status($proc);
$keys = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
foreach ($keys as $k) {
    echo array_key_exists($k, $st) ? "$k ok\n" : "$k missing\n";
}
echo is_int($st['termsig']) ? "termsig int\n" : "termsig bad\n";
echo is_int($st['stopsig']) ? "stopsig int\n" : "stopsig bad\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
command ok
pid ok
running ok
signaled ok
stopped ok
exitcode ok
termsig ok
stopsig ok
termsig int
stopsig int
