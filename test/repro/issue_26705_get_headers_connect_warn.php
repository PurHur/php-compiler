<?php
/** Repro #26705 — get_headers() HTTP connect failure must E_WARNING (php-src ext/standard/head.c). */
error_reporting(E_ALL);

/** @var list<string> */
$GLOBALS['issue_26705_warns'] = [];

// Untyped handler — typed errno int64 is not supported for AOT JIT callbacks (#1379).
function issue_26705_get_headers_warn_handler($no, $msg)
{
    $GLOBALS['issue_26705_warns'][] = $no . ':' . $msg;

    return true;
}

set_error_handler('issue_26705_get_headers_warn_handler');
$r = get_headers('http://127.0.0.1:1/', false);
$warns = $GLOBALS['issue_26705_warns'];
echo 'ret=' . var_export($r, true) . "\n";
echo 'warn_count=' . count($warns) . "\n";
echo 'warn_has=' . ((isset($warns[0]) && str_contains($warns[0], 'Failed to open stream') && str_contains($warns[0], 'get_headers(')) ? 'yes' : 'no') . "\n";
echo 'warn_msg=' . (isset($warns[0]) ? $warns[0] : '') . "\n";
