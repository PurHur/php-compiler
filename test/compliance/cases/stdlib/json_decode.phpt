--TEST--
stdlib json_decode() assoc object (issue #1172)
--FILE--
<?php
$data = json_decode('{"ok":true,"n":1,"msg":"hi"}', true);
echo $data['ok'] ? '1' : '0';
echo $data['n'];
echo $data['msg'];
echo "\n";
--EXPECT--
11hi
