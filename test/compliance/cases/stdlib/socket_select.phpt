--TEST--
stdlib socket_select() multiplex Socket pair (#6395)
--SKIPIF--
<?php
if (!function_exists('socket_create_pair') || !function_exists('socket_select')) {
    echo "skip sockets unavailable\n";
}
--FILE--
<?php
declare(strict_types=1);

echo function_exists('socket_select') ? "fn_ok\n" : "fn_bad\n";

$socks = null;
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks)) {
    echo "pair_fail\n";
    exit(0);
}

$read = [$socks[0]];
$write = null;
$except = null;
$n = socket_select($read, $write, $except, 0);
echo 'idle=', (int) $n, ' rem=', count($read), "\n";

socket_write($socks[1], 'hi');
$read = [$socks[0]];
$write = null;
$except = null;
$n = socket_select($read, $write, $except, 0);
echo 'ready=', (int) $n, ' rem=', count($read), "\n";
echo 'data=', socket_read($socks[0], 10), "\n";

try {
    $bad = ['x'];
    $w = null;
    $e = null;
    socket_select($bad, $w, $e, 0);
    echo "type_miss\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'must only have elements of type Socket') ? 'type_ok' : 'type_bad'), "\n";
}

try {
    $r = $w = $e = null;
    socket_select($r, $w, $e, 0);
    echo "value_miss\n";
} catch (ValueError $e) {
    echo (str_contains($e->getMessage(), 'At least one array') ? 'value_ok' : 'value_bad'), "\n";
}
--EXPECT--
fn_ok
idle=0 rem=0
ready=1 rem=1
data=hi
type_ok
value_ok
