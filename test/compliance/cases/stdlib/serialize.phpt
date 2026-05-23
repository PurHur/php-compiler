--TEST--
stdlib serialize() for assoc array and scalars (issue #1174)
--FILE--
<?php
echo serialize(['ok' => true, 'n' => 1, 'msg' => 'hi']);
echo "\n";
echo serialize(null);
echo "\n";
echo serialize(true);
echo "\n";
echo serialize(42);
echo "\n";
echo serialize('hello');
echo "\n";
--EXPECT--
a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}
N;
b:1;
i:42;
s:5:"hello";
