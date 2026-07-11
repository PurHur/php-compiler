--TEST--
AOT precision INI get/set/restore (issue #11841, ext/standard/ini.c)
--FILE--
<?php
echo ini_get('precision') === '14' ? "default-ok\n" : "default-bad\n";
$old = ini_set('precision', '8');
echo $old === '14' ? "set-old-ok\n" : "set-old-bad\n";
echo ini_get('precision') === '8' ? "set-ok\n" : "set-bad\n";
ini_restore('precision');
echo ini_get('precision') === '14' ? "restore-ok\n" : "restore-bad\n";
--EXPECT--
default-ok
set-old-ok
set-ok
restore-ok
