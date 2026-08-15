<?php
declare(strict_types=1);
/**
 * Issue #31355 — thin AOT socket_select NestedJIT (re-#6395).
 * Happy-path only (TypeError try/catch still uses thin-AOT abort like peer socket builtins).
 */
var_export(function_exists('socket_select'));
echo "\n";

if (!function_exists('socket_create_pair')) {
    echo "skip: no socket_create_pair\n";
    exit(0);
}

$socks = null;
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks)) {
    echo "skip: create_pair failed\n";
    exit(0);
}

$read = [$socks[0]];
$write = null;
$except = null;
$n = socket_select($read, $write, $except, 0);
echo 'idle=', var_export($n, true), ' remaining=', count($read), "\n";

socket_write($socks[1], 'hi');
$read = [$socks[0]];
$write = null;
$except = null;
$n = socket_select($read, $write, $except, 0);
echo 'ready=', var_export($n, true), ' remaining=', count($read), "\n";
echo 'data=', var_export(socket_read($socks[0], 10), true), "\n";
