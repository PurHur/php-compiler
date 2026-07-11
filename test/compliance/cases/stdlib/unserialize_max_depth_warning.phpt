--TEST--
stdlib unserialize() max_depth option emits depth-exceeded E_WARNING (#13715, ext/standard/var.c)
--FILE--
<?php
$payload = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';
$r = unserialize($payload, ['max_depth' => 1]);
var_export($r);
echo "\n";
--EXPECTF--
Warning: unserialize(): Maximum depth of 1 exceeded. The depth limit can be changed using the max_depth unserialize() option or the unserialize_max_depth ini setting in %s on line %d
Notice: unserialize(): Error at offset %d of %d bytes in %s on line %d
false
