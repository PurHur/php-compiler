--TEST--
stdlib serialize() / unserialize() list roundtrip (issues #1174–#1175)
--FILE--
<?php
$list = ['x', 'y'];
$s = serialize($list);
$r = unserialize($s);
echo $r[0], $r[1], "\n";
--EXPECT--
xy
