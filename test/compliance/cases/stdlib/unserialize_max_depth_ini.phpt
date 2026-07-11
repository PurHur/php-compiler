--TEST--
stdlib unserialize_max_depth INI default + ini_set round-trip (issue #13628, ext/standard/ini.c)
--FILE--
<?php
echo ini_get('unserialize_max_depth') === '4096' ? "default-ok\n" : "default-bad\n";
$old = ini_set('unserialize_max_depth', '8');
echo $old === '4096' ? "old-ok\n" : "old-bad\n";
echo ini_get('unserialize_max_depth') === '8' ? "set-ok\n" : "set-bad\n";
--EXPECT--
default-ok
old-ok
set-ok
