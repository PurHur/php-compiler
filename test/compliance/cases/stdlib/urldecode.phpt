--TEST--
stdlib urldecode() and rawurldecode()
--FILE--
<?php
echo urldecode("a+b"), "\n";
echo rawurldecode("a+b"), "\n";
echo urldecode("a%20b"), "\n";
echo rawurldecode("a%20b"), "\n";
echo urldecode(urlencode("a&b")), "\n";
echo rawurldecode(rawurlencode("a/b")), "\n";
echo urldecode("a%zz"), "\n";
--EXPECT--
a b
a+b
a b
a b
a&b
a/b
a%zz
