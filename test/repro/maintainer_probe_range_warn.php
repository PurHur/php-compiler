<?php
/** #29203 — multibyte range() bounds must emit Zend E_WARNINGs under PROFILE≥8.3. */
error_reporting(E_ALL);
$msgs = [];
set_error_handler(function ($n, $m) use (&$msgs) {
    $msgs[] = $m;

    return true;
});
$r = range('あ', 'う');
restore_error_handler();
echo 'count=', count($r), ' first_hex=', bin2hex($r[0] ?? ''), ' warns=', json_encode($msgs), "\n";
