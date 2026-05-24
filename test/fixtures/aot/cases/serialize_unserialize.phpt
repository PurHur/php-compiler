--TEST--
AOT: unserialize() roundtrip from PHP-serialized list (issues #1174–#1175)
--FILE--
<?php
$s = 'a:2:{i:0;i:42;i:1;i:7;}';
$r = unserialize($s);
echo $r[0], $r[1];
echo "\n";
--EXPECT--
427
