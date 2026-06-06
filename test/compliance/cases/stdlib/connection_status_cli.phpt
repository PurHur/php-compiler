--TEST--
stdlib connection_status() CLI returns CONNECTION_NORMAL (issue #6161)
--FILE--
<?php
var_export(function_exists('connection_status'));
echo "\n";
echo connection_status(), "\n";
echo CONNECTION_NORMAL, "\n";
echo connection_status() === CONNECTION_NORMAL ? "match\n" : "bad\n";
--EXPECT--
true
0
0
match
