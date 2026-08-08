--TEST--
stdlib ConnectionStatus phantom absent; connection_status() int (#28931, re-#7234)
--FILE--
<?php
var_export(enum_exists('ConnectionStatus', false));
echo "\n";
var_export(connection_status());
echo "\n";
var_export(connection_status() === CONNECTION_NORMAL);
echo "\n";
--EXPECT--
false
0
true
