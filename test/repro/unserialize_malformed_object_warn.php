<?php

declare(strict_types=1);

// #29204 — malformed O: emits E_WARNING + error_get_last
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs) {
    $msgs[] = [$n, $m];

    return true;
});
$r = unserialize('O:8:"stdClass":1:{');
restore_error_handler();
echo 'r=';
var_export($r);
echo "\nmsgs=", json_encode($msgs), "\n";
error_clear_last();
$r2 = @unserialize('O:8:"stdClass":1:{');
echo 'r2=';
var_export($r2);
echo "\nlast=", json_encode(error_get_last()), "\n";
