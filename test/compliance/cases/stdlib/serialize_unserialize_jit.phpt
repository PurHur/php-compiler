--TEST--
stdlib unserialize() JIT from PHP-serialized list (issues #1174–#1175)
--FILE--
<?php
$r = unserialize('a:2:{i:0;s:1:"x";i:1;s:1:"y";}');
echo $r[0], $r[1], "\n";
--EXPECT--
xy
