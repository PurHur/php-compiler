--TEST--
stdlib mb_parse_str() — query parse (#20015, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_parse_str') ? "exists\n" : "missing\n";
$r = [];
echo mb_parse_str('a=1&b=%E3%81%82', $r) ? "true\n" : "false\n";
echo $r['a'], "\n";
echo $r['b'], "\n";
$empty = ['x' => 1];
echo mb_parse_str('', $empty) ? "true\n" : "false\n";
echo count($empty), "\n";
$nested = [];
mb_parse_str('a[b]=1&c.d=2', $nested);
echo $nested['a']['b'], "\n";
echo $nested['c_d'], "\n";
--EXPECT--
exists
true
1
あ
false
0
1
2
