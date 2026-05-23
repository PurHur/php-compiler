--TEST--
stdlib http_build_query() JIT/AOT path (issue #1169)
--FILE--
<?php
$params = ['page' => 2, 'q' => 'hello world'];
echo http_build_query($params), "\n";
echo http_build_query(['x' => ['y' => 'z']]), "\n";
echo http_build_query(['t' => true, 'f' => false]), "\n";
--EXPECT--
page=2&q=hello+world
x%5By%5D=z
t=1&f=0
