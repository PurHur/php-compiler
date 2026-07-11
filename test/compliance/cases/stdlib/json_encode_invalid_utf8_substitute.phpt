--TEST--
stdlib json_encode() JSON_INVALID_UTF8_SUBSTITUTE — U+FFFD escape (issue #9964, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

$s = "\xB1\x31";
echo json_encode($s, 2097152), "\n";
echo bin2hex(json_encode($s, 2097152)), "\n";
echo json_encode($s, 4194304 | 2097152), "\n";
?>
--EXPECT--
"\ufffd1"
225c75666666643122
"\ufffd1"
