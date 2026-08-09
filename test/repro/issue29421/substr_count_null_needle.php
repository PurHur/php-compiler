<?php
// Repro #29421 — substr_count(null $needle) must DEP then ValueError under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "DEP: $str\n";

    return true;
});
try {
    substr_count('aaa', null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr_count('aaa', '');
    echo "empty_uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
