<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}

$status = proc_get_status($proc);
$hasKey = \array_key_exists('pending_signals', $status);
$isArray = $hasKey && \is_array($status['pending_signals']);

echo 'has_key=', \var_export($hasKey, true), "\n";
echo 'is_array=', \var_export($isArray, true), "\n";

fclose($pipes[1]);
proc_close($proc);

$ok = $hasKey && $isArray;
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
