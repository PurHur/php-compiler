--TEST--
stdlib proc_get_status() — status array key insertion order (ext/standard/proc_open.c, #13210, #17362, #28527)
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
$expected = ['command', 'pid'];
if (array_key_exists('cached', $status)) {
    $expected[] = 'cached';
}
$expected = array_merge($expected, ['running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig']);
if (array_key_exists('pending_signals', $status)) {
    $expected[] = 'pending_signals';
}
$keys = array_keys($status);
echo $keys === $expected ? 'order-ok' : 'order-bad', "\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
order-ok
