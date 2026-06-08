--TEST--
stdlib json_encode()/json_decode() native VM path without host ext/json (#4795)
--FILE--
<?php
echo json_encode([1, 2, 3]), "\n";
echo json_encode(['ok' => true, 'n' => 1, 'msg' => 'hi']), "\n";
$decoded = json_decode('{"a":1,"b":[2,3]}', true);
echo json_encode($decoded), "\n";
--EXPECT--
[1,2,3]
{"ok":true,"n":1,"msg":"hi"}
{"a":1,"b":[2,3]}
