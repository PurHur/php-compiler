--TEST--
stdlib ConnectionStatus enum for connection_status() (#7234, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(enum_exists('ConnectionStatus', false));
echo "\n";
var_export(ConnectionStatus::Normal->name);
echo "\n";
var_export(ConnectionStatus::Normal->value);
echo "\n";
var_export(connection_status());
echo "\n";
var_export(connection_status() === ConnectionStatus::Normal);
echo "\n";
var_export(connection_status()->value === CONNECTION_NORMAL);
echo "\n";
--EXPECT--
true
'Normal'
0
\ConnectionStatus::Normal
true
true
