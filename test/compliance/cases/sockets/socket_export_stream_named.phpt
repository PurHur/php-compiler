--TEST--
socket_export_stream Reflection + named socket (#25133)
--FILE--
<?php
$bits = [];
foreach ((new ReflectionFunction('socket_export_stream'))->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
}
echo implode(',', $bits), "\n";
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$st = socket_export_stream(socket: $sock);
echo (is_resource($st) || $st instanceof Socket) ? "named_ok\n" : "named_bad\n";
$st2 = socket_export_stream($sock);
echo (is_resource($st2) || $st2 instanceof Socket) ? "pos_ok\n" : "pos_bad\n";
--EXPECT--
socket
named_ok
pos_ok
