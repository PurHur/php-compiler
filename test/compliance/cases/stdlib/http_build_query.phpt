--TEST--
stdlib http_build_query() for assoc arrays (issue #1169)
--FILE--
<?php
echo http_build_query(['a' => 1, 'b' => 2]), "\n";
echo http_build_query(['foo' => 'a b', 'bar' => 'x&y']), "\n";
echo http_build_query(['nested' => ['a' => 1, 'b' => 2]]), "\n";
echo http_build_query(['a' => 1, 'b' => 2], '', ';'), "\n";
--EXPECT--
a=1&b=2
foo=a+b&bar=x%26y
nested%5Ba%5D=1&nested%5Bb%5D=2
a=1;b=2
