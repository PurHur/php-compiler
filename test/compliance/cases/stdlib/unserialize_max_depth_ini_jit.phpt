--TEST--
stdlib unserialize_max_depth INI JIT/AOT path (issue #13628)
--FILE--
<?php
echo ini_get('unserialize_max_depth') === '4096' ? "default-ok\n" : "default-bad\n";
ini_set('unserialize_max_depth', '16');
echo ini_get('unserialize_max_depth') === '16' ? "set-ok\n" : "set-bad\n";
--EXPECT--
default-ok
set-ok
