--TEST--
stdlib urldecode() JIT/AOT path
--FILE--
<?php
echo urldecode("a+b"), "\n";
echo rawurldecode("a%20b"), "\n";
echo urldecode(urlencode("x y")), "\n";
--EXPECT--
a b
a b
x y
