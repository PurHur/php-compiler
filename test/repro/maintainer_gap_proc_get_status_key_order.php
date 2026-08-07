<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w']];
$proc = proc_open('echo ok', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: proc_open\n";
    exit(1);
}

$status = proc_get_status($proc);
$expected = ['command', 'pid'];
if (\array_key_exists('cached', $status)) {
    $expected[] = 'cached';
}
$expected = \array_merge($expected, ['running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig']);
if (\array_key_exists('pending_signals', $status)) {
    $expected[] = 'pending_signals';
}
$keys = \array_keys($status);
if ($keys !== $expected) {
    echo 'fail: key order ', \implode(',', $keys), ' expected ', \implode(',', $expected), "\n";
    \fclose($pipes[1]);
    proc_close($proc);
    exit(1);
}

\fclose($pipes[1]);
proc_close($proc);
echo "ok\n";
