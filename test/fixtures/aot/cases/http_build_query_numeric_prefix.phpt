--TEST--
AOT: http_build_query() numeric_prefix parameter (issue #7096)
--FILE--
<?php
echo http_build_query(['123a' => 1, '456b' => 2], numeric_prefix: 'n'), "\n";
echo http_build_query([1 => 'foo', 2 => 'bar'], 'my_'), "\n";
--EXPECT--
123a=1&456b=2
my_1=foo&my_2=bar
