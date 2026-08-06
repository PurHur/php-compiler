<?php

declare(strict_types=1);

// Repro #27854 — socket_export_stream / socket_import_stream Reflection stubs.

$export = new ReflectionFunction('socket_export_stream');
echo 'export_ret=', $export->hasReturnType() ? (string) $export->getReturnType() : 'none', "\n";
$ep = $export->getParameters()[0];
echo 'export_p=$', $ep->getName(), ' type=', $ep->hasType() ? (string) $ep->getType() : '-', "\n";

$import = new ReflectionFunction('socket_import_stream');
echo 'import_ret=', $import->hasReturnType() ? (string) $import->getReturnType() : 'none', "\n";
$ip = $import->getParameters()[0];
echo 'import_p=$', $ip->getName(), ' type=', $ip->hasType() ? (string) $ip->getType() : '-', "\n";

$create = new ReflectionFunction('socket_create');
echo 'create_ret=', $create->hasReturnType() ? (string) $create->getReturnType() : 'none', "\n";

// Named args still accepted
$s = socket_create(domain: AF_INET, type: SOCK_STREAM, protocol: SOL_TCP);
$st = socket_export_stream(socket: $s);
$s2 = socket_import_stream(stream: $st);
echo $s2 instanceof Socket ? 'named_ok' : 'named_fail', "\n";
socket_close(socket: $s);
