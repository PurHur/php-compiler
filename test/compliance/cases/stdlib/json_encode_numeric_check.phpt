--TEST--
stdlib json_encode(JSON_NUMERIC_CHECK) encodes numeric strings as numbers (issue #10601)
--FILE--
<?php
echo json_encode(['1', 2, '3.0'], JSON_NUMERIC_CHECK), "\n";
echo json_encode(['abc', '1e2', ''], JSON_NUMERIC_CHECK), "\n";
echo json_encode('42', JSON_NUMERIC_CHECK), "\n";
?>
--EXPECT--
[1,2,3]
["abc",100,""]
42
