<?php
/**
 * Repro #22489 — PROFILE=8.4 substr(null|'') must not emit "String is truncated".
 * Named handler so the same file AOT-compiles (closures deferred for set_error_handler).
 */
error_reporting(E_ALL);
$GLOBALS['substr_22489_warnings'] = [];
function substr_22489_repro_handler(int $no, string $msg): bool
{
    $GLOBALS['substr_22489_warnings'][] = $msg;

    return true;
}
set_error_handler('substr_22489_repro_handler');
$null = null;
$empty = '';
$ab = 'ab';
$r1 = substr($null, 0, 1);
$r2 = substr($empty, 0, 1);
$r3 = substr($ab, 5, 1);
$r4 = substr($ab, 2, 1);
$truncated = 0;
$dep = 0;
foreach ($GLOBALS['substr_22489_warnings'] as $msg) {
    echo $msg, "\n";
    if (str_contains($msg, 'String is truncated')) {
        $truncated++;
    }
    if (str_contains($msg, 'deprecated') || str_contains($msg, 'Passing null')) {
        $dep++;
    }
}
echo 'r1=', var_export($r1, true), "\n";
echo 'r2=', var_export($r2, true), "\n";
echo 'r3=', var_export($r3, true), "\n";
echo 'r4=', var_export($r4, true), "\n";
echo "truncated_warning=$truncated\n";
echo "dep_warning=$dep\n";
