--TEST--
stdlib mb_parse_str() JIT — query parse (#20015, ext/mbstring/mbstring.c)
--FILE--
<?php
$r = [];
echo mb_parse_str('a=1&b=%E3%81%82', $r) ? "true\n" : "false\n";
echo $r['a'], "\n";
echo $r['b'], "\n";
--EXPECT--
true
1
あ
