--TEST--
stdlib http_build_query() JIT numeric_prefix parameter (issue #7096)
--FILE--
<?php
echo http_build_query(['123a' => 1, '456b' => 2], numeric_prefix: 'n'), "\n";
echo http_build_query(['nested' => ['a' => 1, 'b' => 2]], 'p_'), "\n";
--EXPECT--
123a=1&456b=2
nested%5Ba%5D=1&nested%5Bb%5D=2
