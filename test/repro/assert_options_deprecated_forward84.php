<?php

declare(strict_types=1);

// #29209 — ASSERT_* + assert_options() E_DEPRECATED under PROFILE≥8.4
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs) {
    $msgs[] = $m;

    return true;
});
assert_options(ASSERT_EXCEPTION, 1);
try {
    assert(false);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
restore_error_handler();
echo 'warns=', json_encode($msgs), "\n";
