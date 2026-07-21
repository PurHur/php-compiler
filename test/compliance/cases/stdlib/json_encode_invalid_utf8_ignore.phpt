--TEST--
stdlib json_encode() JSON_INVALID_UTF8_IGNORE strips malformed bytes (issue #21723, ext/json/json_encoder.c)
--FILE--
<?php
declare(strict_types=1);

$s = 'a'.chr(0x80).'b';
var_dump(json_encode($s, 1048576));
var_dump(json_last_error());

$s2 = "\xC3\x28";
var_dump(json_encode($s2, 1048576));

$s3 = "\xB1\x31";
var_dump(json_encode($s3, 1048576));
// IGNORE wins over SUBSTITUTE when both set
var_dump(json_encode($s, 1048576 | 2097152));
?>
--EXPECT--
string(4) ""ab""
int(0)
string(3) ""(""
string(3) ""1""
string(4) ""ab""
