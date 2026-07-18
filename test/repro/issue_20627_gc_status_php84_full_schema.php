<?php
// #20627 — PROFILE=8.4 gc_status must match php-src 8.3+ 12-key schema
$s = gc_status();
$required = [
    'running', 'protected', 'full', 'runs', 'collected', 'threshold',
    'buffer_size', 'roots', 'application_time', 'collector_time',
    'destructor_time', 'free_time',
];
$missing = [];
foreach ($required as $key) {
    if (!array_key_exists($key, $s)) {
        $missing[] = $key;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'missing: '.implode(',', $missing)."\nkeys=".implode(',', array_keys($s))."\n");
    exit(1);
}
echo 'keys=', implode(',', array_keys($s)), "\n";
echo "ok\n";
