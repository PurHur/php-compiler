--TEST--
stdlib serialize() float formatting with serialize_precision INI (issue #7103, php-src var.c)
--FILE--
<?php
ini_set('serialize_precision', '2');
echo serialize(1.239);
echo "\n";
echo serialize(['x' => 1.239]);
echo "\n";
--EXPECT--
d:1.2;
a:1:{s:1:"x";d:1.2;}
