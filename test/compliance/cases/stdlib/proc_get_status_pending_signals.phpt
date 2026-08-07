--TEST--
stdlib proc_get_status() pending_signals absent on PROFILE=8.3 (php-src never ships it; #28527, re-#16707/#17907)
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
echo array_key_exists('pending_signals', $status) ? 'pending-present' : 'pending-absent', "\n";
echo array_key_exists('cached', $status) ? 'cached-present' : 'cached-absent', "\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
pending-absent
cached-present
