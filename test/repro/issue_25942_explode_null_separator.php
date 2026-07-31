<?php
// #25942 — explode(null) under php-src-strict (default 8.2): deprecate null→"" then ValueError.
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'WARN:', $str, "\n";

    return true;
});
try {
    var_export(explode(null, 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(explode('', 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
