<?php
// #31464 — user_error(..., null) soft-null DEP + ValueError ($error_level)
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    user_error('x', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    trigger_error('x', null);
    echo "trigger ok\n";
} catch (Throwable $e) {
    echo 'trigger ', get_class($e), ': ', $e->getMessage(), "\n";
}
