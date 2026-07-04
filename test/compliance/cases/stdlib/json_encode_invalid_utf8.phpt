--TEST--
stdlib json_encode() invalid UTF-8 returns false + JSON_ERROR_UTF8 (issue #9205, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

$s = "\xC3\x28";
var_dump(json_encode($s));
var_dump(json_last_error(), json_last_error_msg());
?>
--EXPECT--
bool(false)
int(5)
string(56) "Malformed UTF-8 characters, possibly incorrectly encoded"
