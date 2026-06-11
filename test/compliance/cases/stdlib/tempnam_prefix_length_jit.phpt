--TEST--
stdlib tempnam() prefix truncation JIT (#4401)
--FILE--
<?php
$dir = sys_get_temp_dir();
$prefix = 'begin_' . str_repeat('x', 300) . '_end';
$f = tempnam($dir, $prefix);
echo strlen(basename($f)), "\n";
@unlink($f);
--EXPECT--
69
