<?php
/** #28978 — strtr() replace_pairs nested array value coerces to "Array" (php-src string.c). */
error_reporting(E_ALL);
$warns = [];
set_error_handler(static function (int $no, string $msg) use (&$warns): bool {
    $warns[] = $no.':'.$msg;

    return true;
});
// Unused nested value must not warn (php_strtr_array lazy zval_get_tmp_string).
echo strtr('hi', ['h' => 'H', 'u' => ['x']]), "\n";
echo 'unused_warns=', json_encode($warns), "\n";
$warns = [];
echo strtr('hi', ['h' => ['x']]), "\n";
echo 'used_warns=', json_encode($warns), "\n";
try {
    echo strtr('hi', ['h' => new stdClass()]), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
