--TEST--
stdlib parse_ini_string() syntax warning — virtual filename and line (#11076, ext/standard/ini.c)
--FILE--
<?php
@parse_ini_string('on=foo');
$msg = error_get_last()['message'] ?? '';
echo str_contains($msg, 'Unknown on line 1') ? "ok\n" : "fail\n";
--EXPECT--
ok
