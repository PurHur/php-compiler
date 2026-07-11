--TEST--
stdlib json_encode() preserves negative zero (issue #12393, ext/json/php_json_encoder.c)
--FILE--
<?php
declare(strict_types=1);

echo json_encode(-0.0), "\n";
echo json_encode(0.0), "\n";
?>
--EXPECT--
-0
0
