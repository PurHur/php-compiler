--TEST--
stdlib json_encode() — integer-keyed lists and object keys (issue #3520, ext/json/php_json.c)
--FILE--
<?php
echo json_encode([1, 2, 3]), "\n";
echo json_encode([0 => 'a', 1 => 'b']), "\n";
echo json_encode(['k' => 1, 0 => 2]), "\n";
echo json_encode([1 => 'only']), "\n";
--EXPECT--
[1,2,3]
["a","b"]
{"k":1,"0":2}
{"1":"only"}
