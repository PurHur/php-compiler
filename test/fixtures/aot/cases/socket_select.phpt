--TEST--
socket_select() thin AOT via NestedJIT (#31355 / #6395)
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('socket_create_pair')) {
    echo "skip\n";
    return;
}
$socks = null;
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks)) {
    echo "skip\n";
    return;
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
?>
--EXPECT--
idle=0 remaining=0
ready=1 remaining=1
data='hi'
