--TEST--
stdlib parse_ini_string() unclosed section — E_WARNING expects ']' (#12857, ext/standard/ini.c)
--FILE--
<?php
@parse_ini_string("a=1\n[sec");
$msg = error_get_last()['message'] ?? '';
echo str_contains($msg, "expecting ']'") ? "warn-ok\n" : "warn-fail\n";
var_export(@parse_ini_string("a=1\n[sec"));
--EXPECT--
warn-ok
false
