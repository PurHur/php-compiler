<?php
/**
 * Repro #22828 — $false[] = $v emits E_DEPRECATED then promotes (zend_execute.c).
 * Zend: E_DEPRECATED "Automatic conversion of false to array is deprecated" + array(0=>1).
 */
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});

$f = false;
$f[] = 1;
$ok = 1 === count($seen)
    && str_contains($seen[0], 'Automatic conversion of false to array is deprecated')
    && is_array($f)
    && [1] === $f;
echo $ok ? "false_append_deprecated_ok\n" : "false_append_deprecated_bad\n";
echo gettype($f), "\n";

$tOk = false;
try {
    $t = true;
    $t[] = 1;
} catch (Error $e) {
    $tOk = 'Cannot use a scalar value as an array' === $e->getMessage();
}
echo $tOk ? "true_still_fatal_ok\n" : "true_still_fatal_bad\n";
