--TEST--
stdlib sscanf() — return array without by-ref targets (#10509, ext/standard/sscanf.c)
--FILE--
<?php
$r = sscanf('abc123', '%3s%d');
echo isset($r[0]) ? $r[0] : 'null', "\n";
echo isset($r[1]) ? (string) $r[1] : 'null', "\n";
--EXPECT--
abc
123
