--TEST--
gethostbyname named args (JIT, issue #23492)
--FILE--
<?php
$ip = gethostbyname(hostname: 'localhost');
var_export(is_string($ip));
echo "\n";
--EXPECT--
true
