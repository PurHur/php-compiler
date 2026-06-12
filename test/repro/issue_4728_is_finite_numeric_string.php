<?php
// Issue #4728 repro: is_finite()/is_infinite()/is_nan() Z_PARAM_DOUBLE parity (ext/standard/math.c).
var_export(is_finite('3.14'));
echo "\n";
var_export(is_nan('3.14'));
echo "\n";
try {
    is_finite([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
