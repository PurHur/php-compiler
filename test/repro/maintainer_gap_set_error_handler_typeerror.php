<?php
// php-src-strict repro for #12152 — non-callable string must TypeError.
try {
    set_error_handler('not_a_real_function_xyz');
    echo "no_throw\n";
} catch (\TypeError $e) {
    echo "ok\n";
}
