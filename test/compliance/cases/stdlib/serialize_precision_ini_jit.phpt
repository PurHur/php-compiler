--TEST--
stdlib serialize_precision INI JIT/AOT path (issue #7100)
--FILE--
<?php
echo ini_get('serialize_precision') === '-1' ? "default-ok\n" : "default-bad\n";
$old = ini_set('serialize_precision', '4');
echo is_string($old) && $old === '-1' ? "old-ok\n" : "old-bad\n";
echo ini_get('serialize_precision') === '4' ? "set-ok\n" : "set-bad\n";
--EXPECT--
default-ok
old-ok
set-ok
