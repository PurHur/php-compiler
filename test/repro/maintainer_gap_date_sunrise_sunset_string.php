<?php

declare(strict_types=1);

/**
 * Maintainer repro: date_sunrise()/date_sunset() SUNFUNCS_RET_STRING parity (#16640).
 */

$ts = gmmktime(12, 0, 0, 6, 21, 2020);
$riseStr = date_sunrise($ts, SUNFUNCS_RET_STRING, 40.7, -74.0);
$setStr = date_sunset($ts, SUNFUNCS_RET_STRING, 40.7, -74.0);
$riseTs = date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, 40.7, -74.0);
$setTs = date_sunset($ts, SUNFUNCS_RET_TIMESTAMP, 40.7, -74.0);

$ok = true;
if (!is_string($riseStr) || !preg_match('/^\d{2}:\d{2}$/', $riseStr)) {
    echo "fail: rise string ", var_export($riseStr, true), "\n";
    $ok = false;
}
if (!is_string($setStr) || !preg_match('/^\d{2}:\d{2}$/', $setStr)) {
    echo "fail: set string ", var_export($setStr, true), "\n";
    $ok = false;
}
if ('09:23' !== $riseStr) {
    echo "fail: rise expected 09:23 got ", $riseStr, "\n";
    $ok = false;
}
if ('00:32' !== $setStr) {
    echo "fail: set expected 00:32 got ", $setStr, "\n";
    $ok = false;
}
if (1592731413 !== $riseTs) {
    echo "fail: rise ts expected 1592731413 got ", $riseTs, "\n";
    $ok = false;
}
if (1592785940 !== $setTs) {
    echo "fail: set ts expected 1592785940 got ", $setTs, "\n";
    $ok = false;
}

if ($ok) {
    echo "ok rise={$riseStr} set={$setStr}\n";
    exit(0);
}
exit(1);
