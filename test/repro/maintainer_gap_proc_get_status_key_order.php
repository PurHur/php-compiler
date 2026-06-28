<?php

declare(strict_types=1);

$expected = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];

$desc = [1 => ['pipe', 'w']];
$proc = proc_open('echo ok', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: proc_open\n";
    exit(1);
}

$keys = \array_keys(proc_get_status($proc));
if ($keys !== $expected) {
    echo 'fail: key order ', \implode(',', $keys), ' expected ', \implode(',', $expected), "\n";
    \fclose($pipes[1]);
    proc_close($proc);
    exit(1);
}

\fclose($pipes[1]);
proc_close($proc);
echo "ok\n";
