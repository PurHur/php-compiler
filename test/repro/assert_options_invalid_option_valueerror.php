<?php

// #30524 — assert_options() invalid $option → ValueError (php-src assert.c)
function ao_invalid_option_err($n, $m)
{
    echo 'E:', $m, "\n";

    return true;
}
set_error_handler('ao_invalid_option_err');
foreach ([0, 999, null] as $v) {
    echo '=== ';
    var_export($v);
    echo " ===\n";
    try {
        var_export(assert_options($v));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo "set invalid:\n";
try {
    var_export(assert_options(999, 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'active=', var_export(assert_options(ASSERT_ACTIVE), true), "\n";
