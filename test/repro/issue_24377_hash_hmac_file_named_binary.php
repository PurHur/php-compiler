<?php
/** Repro for #24377 — hash_hmac_file Reflection binary + named args. */
$r = new ReflectionFunction('hash_hmac_file');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
$td = sys_get_temp_dir().'/h'.getmypid();
file_put_contents($td, 'x');
try {
    echo substr(hash_hmac_file(algo: 'sha256', filename: $td, key: 'k', binary: false), 0, 8), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    hash_hmac_file(algo: 'sha256', filename: $td, key: 'k', raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
unlink($td);
