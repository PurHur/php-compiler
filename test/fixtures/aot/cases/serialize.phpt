--TEST--
AOT serialize() for assoc array (issue #1174)
--FILE--
<?php
echo serialize(['ok' => true, 'n' => 1, 'msg' => 'hi']);
--EXPECT--
a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}
