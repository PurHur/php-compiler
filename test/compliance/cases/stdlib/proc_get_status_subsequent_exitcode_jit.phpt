--TEST--
stdlib proc_get_status() subsequent exitcode is -1 JIT (#23722, ext/standard/proc_open.c)
--JIT--
--FILE--
<?php
$d = [1 => ['pipe', 'w']];
$p = proc_open('true', $d, $pipes);
if (!is_resource($p)) {
    echo "no-proc\n";
    exit(1);
}
fclose($pipes[1]);
while (($s = proc_get_status($p))['running']) {
    usleep(5000);
}
echo 'first=', $s['exitcode'], "\n";
echo 'second=', proc_get_status($p)['exitcode'], "\n";
echo 'third=', proc_get_status($p)['exitcode'], "\n";
echo 'running=', proc_get_status($p)['running'] ? '1' : '0', "\n";
proc_close($p);
--EXPECT--
first=0
second=-1
third=-1
running=0
