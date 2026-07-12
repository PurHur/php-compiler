<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: proc_open\n";
    exit(1);
}

fclose($pipes[1]);
fclose($pipes[2]);

for ($i = 0; $i < 50; ++$i) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        break;
    }
    usleep(10000);
}

if (\array_key_exists('cached', $status)) {
    echo "fail: cached key present on reference profile\n";
    proc_close($proc);
    exit(1);
}

$expected = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
$keys = \array_keys($status);
if ($keys !== $expected) {
    echo 'fail: keys ', \implode(',', $keys), ' expected ', \implode(',', $expected), "\n";
    proc_close($proc);
    exit(1);
}

proc_close($proc);
echo "no_cached\n";
