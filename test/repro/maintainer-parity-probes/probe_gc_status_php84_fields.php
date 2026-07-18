<?php
$s = gc_status();
$required = [
    'running', 'protected', 'full', 'runs', 'collected', 'threshold',
    'buffer_size', 'roots', 'application_time', 'collector_time',
    'destructor_time', 'free_time',
];
foreach ($required as $key) {
    if (!array_key_exists($key, $s)) {
        fwrite(STDERR, "missing key: {$key}\n");
        exit(1);
    }
}
echo "ok\n";
