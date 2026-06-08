--TEST--
stdlib http_build_query() numeric_prefix parameter (issue #7096)
--FILE--
<?php
echo http_build_query(['123a' => 1, '456b' => 2], numeric_prefix: 'n'), "\n";
echo http_build_query([1 => 'foo', 2 => 'bar'], 'my_'), "\n";
echo http_build_query(['nested' => [0 => 'a', 1 => 'b']], 'p_'), "\n";
echo http_build_query([0 => 'foo', 'bar' => 'baz'], 'n'), "\n";
--EXPECT--
123a=1&456b=2
my_1=foo&my_2=bar
nested%5B0%5D=a&nested%5B1%5D=b
n0=foo&bar=baz
