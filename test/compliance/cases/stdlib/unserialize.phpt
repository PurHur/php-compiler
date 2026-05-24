--TEST--
stdlib unserialize() for assoc array and scalars (issue #1175)
--FILE--
<?php
$data = unserialize('a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}');
echo $data['ok'] ? '1' : '0';
echo $data['n'];
echo $data['msg'];
echo "\n";
echo unserialize('N;') === null ? 'n' : 'x';
echo "\n";
echo unserialize('b:1;') ? '1' : '0';
echo "\n";
echo unserialize('i:42;');
echo "\n";
echo unserialize('s:5:"hello";');
echo "\n";
$round = unserialize(serialize(['ok' => true, 'n' => 1, 'msg' => 'hi']));
echo serialize($round);
echo "\n";
--EXPECT--
11hi
n
1
42
hello
a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}
