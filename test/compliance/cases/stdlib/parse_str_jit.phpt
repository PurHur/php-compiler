--TEST--
stdlib parse_str() JIT/AOT path (issue #1367)
--FILE--
<?php
$params = [];
parse_str('page=2&q=hello+world', $params);
echo $params['page'], ' ', $params['q'], "\n";

parse_str('nested%5Bx%5D=y&nested%5Bz%5D=9', $params);
echo $params['nested']['x'], ' ', $params['nested']['z'], "\n";
--EXPECT--
2 hello world
y 9
