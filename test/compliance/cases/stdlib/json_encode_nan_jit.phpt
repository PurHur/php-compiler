--TEST--
stdlib json_encode() JIT returns false for NAN/INF (issue #3606)
--FILE--
<?php
$r = json_encode(NAN);
var_dump($r);
echo json_last_error() === 7 ? '7' : 'n', "\n";
$r = json_encode(-INF);
var_dump($r);
echo json_last_error() === 7 ? '7' : 'n', "\n";
--EXPECT--
bool(false)
7
bool(false)
7
