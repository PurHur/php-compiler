--TEST--
stdlib unserialize() roundtrip with serialize() (issue #1175)
--FILE--
<?php
$payload = serialize(['ok' => true, 'n' => 1, 'msg' => 'hi']);
$data = unserialize($payload);
echo $data['ok'] ? '1' : '0';
echo $data['n'];
echo $data['msg'];
echo "\n";
echo unserialize('N;') === null ? 'null' : 'bad';
echo "\n";
echo unserialize('bad') === false ? 'fail' : 'bad';
echo "\n";
--EXPECT--
11hi
null
fail
