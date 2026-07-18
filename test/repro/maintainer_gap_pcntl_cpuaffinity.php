<?php
declare(strict_types=1);

// Repro for #20510 — pcntl CPU affinity / getcpu
foreach (['pcntl_getcpuaffinity', 'pcntl_setcpuaffinity', 'pcntl_getcpu'] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
if (function_exists('pcntl_getcpuaffinity')) {
    $cpus = pcntl_getcpuaffinity();
    echo 'cpus=', is_array($cpus) ? count($cpus) : 'false', PHP_EOL;
    echo 'getcpu=', pcntl_getcpu(), PHP_EOL;
    if (is_array($cpus) && $cpus !== []) {
        $ok = pcntl_setcpuaffinity(null, [$cpus[0]]);
        echo 'set=', $ok ? '1' : '0', PHP_EOL;
        @pcntl_setcpuaffinity(null, $cpus);
    }
}
