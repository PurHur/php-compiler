--TEST--
stdlib json_encode() for assoc array (issue #61)
--FILE--
<?php
echo json_encode(['ok' => true, 'n' => 1, 'msg' => 'hi']);
--EXPECT--
{"ok":true,"n":1,"msg":"hi"}
