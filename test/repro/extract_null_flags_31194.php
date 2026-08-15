<?php
// Repro #31194 — extract(..., null) Z_PARAM_LONG soft-null DEP + EXTR_OVERWRITE
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "E{$errno}:{$errstr}\n";

    return true;
});
$arr = ['a' => 1];
try {
    $n = extract($arr, null);
    var_export($n);
    echo "\n";
    echo 'a=', $a ?? 'undef', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
