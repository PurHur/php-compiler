--TEST--
stdlib socket_bind/listen AF_UNIX filesystem path (#20268, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir() . '/phpc-afunix-' . getmypid() . '.sock';
@unlink($path);

$s = socket_create(AF_UNIX, SOCK_STREAM, 0);
echo 'create=', (int) ($s instanceof Socket), "\n";
$b = socket_bind($s, $path);
echo 'bind=', (int) $b, ' err=', socket_last_error($s), "\n";
$l = socket_listen($s, 1);
echo 'listen=', (int) $l, ' err=', socket_last_error($s), "\n";

$c = socket_create(AF_UNIX, SOCK_STREAM, 0);
$ok = socket_connect($c, $path);
echo 'connect=', (int) $ok, "\n";
$peer = socket_accept($s);
echo 'accept=', (int) ($peer instanceof Socket), "\n";

$n = socket_send($c, 'hi', 2, 0);
echo 'sent=', $n, "\n";
$buf = '';
$got = socket_recv($peer, $buf, 8, 0);
echo 'recv=', $got, ' buf=', $buf, "\n";

socket_close($c);
socket_close($peer);
socket_close($s);
@unlink($path);
echo "done\n";
--EXPECT--
create=1
bind=1 err=0
listen=1 err=0
connect=1
accept=1
sent=2
recv=2 buf=hi
done
