<?php
declare(strict_types=1);
$socks = null;
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks);
$read = [$socks[0]];
$w = null;
$e = null;
$n = socket_select($read, $w, $e, 0);
echo 'n=', var_export($n, true), ' rem=', count($read), "\n";
socket_write($socks[1], 'hi');
$read = [$socks[0]];
$w = null;
$e = null;
$n = socket_select($read, $w, $e, 0);
echo 'ready=', var_export($n, true), ' rem=', count($read), "\n";
echo 'data=', var_export(socket_read($socks[0], 10), true), "\n";
