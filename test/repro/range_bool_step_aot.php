<?php

/**
 * AOT repro for #29505 — range() bool $step Z_PARAM_NUMBER coerce.
 * Compile: php bin/compile.php -o /tmp/range_bool_step test/repro/range_bool_step_aot.php
 */
error_reporting(E_ALL);
try {
    echo implode(',', range(1, 5, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    echo implode(',', range(1, 5, false)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
