--TEST--
stdlib proc_get_status() pending_signals on PHP_COMPILER_PROFILE=8.3 (ext/standard/proc_open.c, #16707, #17907)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$status = proc_get_status($proc);
$has = array_key_exists('pending_signals', $status);
$list = $has && is_array($status['pending_signals']);
echo ($has && $list) ? 'pending-ok' : 'pending-bad', "\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
pending-ok
