--TEST--
stdlib unserialize() max_depth option matches Zend (ext/standard/var_unserializer.c, #3300)
--FILE--
<?php
$s = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';
var_export(unserialize($s, ['max_depth' => 2]) === false);
echo "\n";
$ok = unserialize($s, ['max_depth' => 4]);
var_export($ok[0][0][0] === 1);
echo "\n";
--EXPECT--
true
true
