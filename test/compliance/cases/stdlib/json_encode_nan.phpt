--TEST--
stdlib json_encode() returns false for NAN/INF (issue #3606, ext/json/php_json.c)
--FILE--
<?php
$r = json_encode(NAN);
var_dump($r);
echo json_last_error() === 7 ? '7' : 'n', "\n";
$r = json_encode(INF);
var_dump($r);
echo json_last_error() === 7 ? '7' : 'n', "\n";
echo json_encode(1.5), "\n";
--EXPECT--
bool(false)
7
bool(false)
7
1.5
