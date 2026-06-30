<?php

declare(strict_types=1);

$status = gc_status();
$keys = array_keys($status);
sort($keys);
echo implode(',', $keys), "\n";
echo 'running='.(array_key_exists('running', $status) ? 'yes' : 'no'), "\n";
echo 'runs='.(array_key_exists('runs', $status) ? 'yes' : 'no'), "\n";

$want82 = ['collected', 'roots', 'runs', 'threshold'];
$missing = array_diff($want82, $keys);
if ([] !== $missing) {
    echo 'fail: missing 8.2 keys: '.implode(',', $missing)."\n";
    exit(1);
}

echo "ok\n";
