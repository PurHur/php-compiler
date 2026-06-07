--TEST--
stdlib serialize_precision INI + serialize() float formatting (issue #7100, php-src var.c)
--FILE--
<?php
echo ini_get('serialize_precision') === '-1' ? "default-ok\n" : "default-bad\n";
ini_set('serialize_precision', '2');
echo ini_get('serialize_precision') === '2' ? "set-ok\n" : "set-bad\n";
echo serialize(1.239);
echo "\n";
--EXPECT--
default-ok
set-ok
d:1.2;
