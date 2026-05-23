--TEST--
stdlib json_encode() JIT (issue #61)
--FILE--
<?php
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
echo "\n";
echo json_encode(['ok' => true, 'n' => 1, 'msg' => 'hi']);
--EXPECT--
{"ok":true,"service":"php-compiler"}
{"ok":true,"n":1,"msg":"hi"}
