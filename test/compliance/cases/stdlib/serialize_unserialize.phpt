--TEST--
stdlib serialize() / unserialize() assoc roundtrip (issues #1174–#1175)
--FILE--
<?php
$data = ['ok' => true, 'n' => 1, 'msg' => 'hi'];
$s = serialize($data);
$r = unserialize($s);
echo $r['ok'] ? '1' : '0';
echo $r['n'];
echo $r['msg'];
echo "\n";
--EXPECT--
11hi
