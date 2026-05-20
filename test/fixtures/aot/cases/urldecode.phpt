--TEST--
AOT urldecode() and rawurldecode()
--FILE--
<?php
echo urldecode("a+b"), "\n";
echo rawurldecode("a%20b"), "\n";
echo urldecode(urlencode("a&b")), "\n";
--EXPECT--
a b
a b
a&b
