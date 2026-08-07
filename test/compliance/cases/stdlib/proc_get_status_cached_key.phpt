--TEST--
Stdlib: proc_get_status() cached key after child exit (#17362, #28527, PHP 8.3+ forward profile)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
fclose($pipes[1]);
fclose($pipes[2]);
for ($i = 0; $i < 50; ++$i) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        break;
    }
    usleep(10000);
}
echo array_key_exists('cached', $status) ? "has\n" : "missing\n";
echo $status['cached'] ? "true\n" : "false\n";
echo array_key_exists('pending_signals', $status) ? "pending\n" : "no-pending\n";
echo $status['running'] ? "running\n" : "done\n";
$keys = array_keys($status);
echo implode(',', $keys), "\n";
proc_close($proc);
--EXPECT--
has
true
no-pending
done
command,pid,cached,running,signaled,stopped,exitcode,termsig,stopsig
