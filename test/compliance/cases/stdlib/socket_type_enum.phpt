--TEST--
stdlib SocketType enum — backed cases materialize (#7235, ext/sockets/sockets.stub.php)
--FILE--
<?php
var_export(class_exists('SocketType', false));
echo "\n";
var_export(SocketType::Stream->name);
echo "\n";
var_export(SocketType::Stream->value);
echo "\n";
var_export(SocketType::Datagram->value);
echo "\n";
$case = SocketType::Stream;
var_export($case instanceof SocketType);
echo "\n";
--EXPECT--
true
'Stream'
1
2
true
