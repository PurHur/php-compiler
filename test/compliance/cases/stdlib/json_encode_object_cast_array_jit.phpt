--TEST--
stdlib json_encode() array-cast object encodes with numeric-string keys JIT (issue #12392)
--FILE--
<?php
declare(strict_types=1);

echo json_encode((object) [1, 2]), "\n";
echo json_last_error(), "\n";
?>
--EXPECT--
{"0":1,"1":2}
0
