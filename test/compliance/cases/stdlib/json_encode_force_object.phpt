--TEST--
stdlib json_encode() JSON_FORCE_OBJECT encodes list arrays as objects (#10555)
--FILE--
<?php
echo json_encode([1, 2], JSON_FORCE_OBJECT), "\n";
echo json_encode([1, 2], 16), "\n";
echo json_encode([], JSON_FORCE_OBJECT), "\n";
echo json_encode([[1, 2]], JSON_FORCE_OBJECT), "\n";
?>
--EXPECT--
{"0":1,"1":2}
{"0":1,"1":2}
{}
{"0":{"0":1,"1":2}}
