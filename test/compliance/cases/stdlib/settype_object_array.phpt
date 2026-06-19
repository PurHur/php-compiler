--TEST--
stdlib settype() object to array — property hash not index-0 wrap (#9963, ext/standard/type.c)
--FILE--
<?php
class C { public int $a = 1; private int $b = 2; }
$o = new stdClass();
settype($o, 'array');
echo count($o), "\n";
$c = new C();
settype($c, 'array');
echo $c['a'], "\n";
echo $c["\0C\0b"], "\n";
--EXPECT--
0
1
2
