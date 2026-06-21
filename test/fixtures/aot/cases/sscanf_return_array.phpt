--TEST--
AOT: sscanf() return array with field width (#10509)
--FILE--
<?php
$r = sscanf('abc123', '%3s%d');
echo isset($r[0]) ? $r[0] : 'null', "\n";
echo isset($r[1]) ? (string) $r[1] : 'null', "\n";
--EXPECT--
abc
123
