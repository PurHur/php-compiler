<?php
/**
 * Repro #28556 — PROFILE≥8.4 substr(..., PHP_INT_MAX) must not emit "String is truncated".
 * Zend/php-src clamps oversize positive length silently (ext/standard/string.c php_substr).
 * Named handler so the same file AOT-compiles (closures deferred for set_error_handler).
 */
error_reporting(E_ALL);
$GLOBALS['substr_28556_warnings'] = [];
function substr_28556_repro_handler(int $no, string $msg): bool
{
    $GLOBALS['substr_28556_warnings'][] = $msg;

    return true;
}
set_error_handler('substr_28556_repro_handler');
$out = substr('abc', 1, PHP_INT_MAX);
$over = substr('hello', 0, 50);
$truncated = 0;
foreach ($GLOBALS['substr_28556_warnings'] as $msg) {
    echo $msg, "\n";
    if (str_contains($msg, 'String is truncated')) {
        $truncated++;
    }
}
echo 'out=', var_export($out, true), "\n";
echo 'over=', var_export($over, true), "\n";
echo "truncated_warning=$truncated\n";
