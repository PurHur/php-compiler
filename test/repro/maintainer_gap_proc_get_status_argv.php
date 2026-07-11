<?php

declare(strict_types=1);

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['/bin/echo', 'hi'], $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: proc_open\n";
    exit(1);
}

$st = proc_get_status($proc);
if ('/bin/echo' !== ($st['command'] ?? null)) {
    echo 'fail: command=', \var_export($st['command'] ?? null, true), "\n";
    proc_close($proc);
    exit(1);
}
if (!($st['running'] ?? false)) {
    echo "fail: running=false\n";
    proc_close($proc);
    exit(1);
}
if (!isset($st['pid']) || !\is_int($st['pid']) || $st['pid'] <= 0) {
    echo "fail: pid\n";
    proc_close($proc);
    exit(1);
}

proc_close($proc);
echo "ok\n";
