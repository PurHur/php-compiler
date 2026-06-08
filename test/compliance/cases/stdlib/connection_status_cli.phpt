--TEST--
stdlib connection_status() CLI returns ConnectionStatus::Normal (issues #6161, #7234)
--FILE--
<?php
var_export(function_exists('connection_status'));
echo "\n";
var_export(connection_status());
echo "\n";
echo connection_status()->value, "\n";
echo CONNECTION_NORMAL, "\n";
echo connection_status() === ConnectionStatus::Normal ? "match\n" : "bad\n";
--EXPECT--
true
\ConnectionStatus::Normal
0
0
match
