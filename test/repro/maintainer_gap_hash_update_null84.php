<?php
/** Repro for #21557 — hash_update(null) soft-null under PROFILE=8.4 (reverts #20195 TypeError). */
$c = hash_init('sha1');
try {
    hash_update($c, null);
    echo 'coerced:', hash_final($c), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
