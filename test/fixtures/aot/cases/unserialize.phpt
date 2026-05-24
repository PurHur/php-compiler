--TEST--
AOT unserialize() roundtrip (issue #1175)
--FILE--
<?php
$data = unserialize('a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}');
echo $data['ok'] ? '1' : '0';
echo $data['n'];
echo $data['msg'];
echo "\n";
echo serialize(unserialize(serialize(['ok' => true, 'n' => 1])));
--EXPECT--
11hi
a:2:{s:2:"ok";b:1;s:1:"n";i:1;}
