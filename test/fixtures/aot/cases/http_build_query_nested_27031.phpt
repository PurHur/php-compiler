--TEST--
AOT http_build_query nested array (#27031)
--FILE--
<?php
echo http_build_query(['a' => 1, 'b' => ['c' => 2]]), "\n";
echo http_build_query(['a' => 1, 'b' => 2]), "\n";
echo http_build_query(['x' => 'y z']), "\n";
--EXPECT--
a=1&b%5Bc%5D=2
a=1&b=2
x=y+z
