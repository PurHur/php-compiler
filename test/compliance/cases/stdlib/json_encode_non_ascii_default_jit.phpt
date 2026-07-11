--TEST--
stdlib json_encode() default escapes non-ASCII as \\uXXXX JIT (issue #12391)
--FILE--
<?php
declare(strict_types=1);

echo json_encode("\xE2\x82\xAC"), "\n";
echo json_encode("\xE2\x82\xAC", JSON_UNESCAPED_UNICODE), "\n";
?>
--EXPECT--
"\u20ac"
"€"
