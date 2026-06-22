--TEST--
AOT: json_encode() JSON_FORCE_OBJECT (#10555)
--FILE--
<?php
echo json_encode([1, 2], JSON_FORCE_OBJECT), "\n";
echo json_encode([1, 2], 16), "\n";
?>
--EXPECT--
{"0":1,"1":2}
{"0":1,"1":2}
