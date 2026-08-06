--TEST--
socket_export_stream / socket_import_stream Reflection Socket stubs (#27854)
--SKIPIF--
<?php
if (!function_exists('socket_export_stream') || !function_exists('socket_import_stream')) {
    die('skip sockets');
}
?>
--FILE--
<?php
declare(strict_types=1);

$export = new ReflectionFunction('socket_export_stream');
echo $export->hasReturnType() ? 'export_has_ret' : 'export_no_ret', "\n";
$ep = $export->getParameters()[0];
echo '$', $ep->getName(), ':', $ep->hasType() ? (string) $ep->getType() : '-', "\n";

$import = new ReflectionFunction('socket_import_stream');
echo (string) $import->getReturnType(), "\n";
$ip = $import->getParameters()[0];
echo '$', $ip->getName(), ':', $ip->hasType() ? (string) $ip->getType() : '-', "\n";

$create = new ReflectionFunction('socket_create');
echo (string) $create->getReturnType(), "\n";

$s = socket_create(domain: AF_INET, type: SOCK_STREAM, protocol: SOL_TCP);
$st = socket_export_stream(socket: $s);
$s2 = socket_import_stream(stream: $st);
echo $s2 instanceof Socket ? 'named_ok' : 'named_fail', "\n";
socket_close(socket: $s);
?>
--EXPECT--
export_no_ret
$socket:Socket
Socket|false
$stream:-
Socket|false
named_ok
