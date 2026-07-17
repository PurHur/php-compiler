<?php
/** Repro for #20195 — hash_update(null) TypeError under PROFILE=8.4. */
$c = hash_init('sha1');
try {
    hash_update($c, null);
    echo 'coerced:', hash_final($c), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
