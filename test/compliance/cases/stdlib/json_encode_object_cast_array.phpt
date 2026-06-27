--TEST--
stdlib json_encode() array-cast object encodes with numeric-string keys (issue #12392, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

echo json_encode((object) [1, 2]), "\n";
echo json_last_error(), "\n";
?>
--EXPECT--
{"0":1,"1":2}
0
