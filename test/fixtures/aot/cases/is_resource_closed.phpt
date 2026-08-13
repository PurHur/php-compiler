--TEST--
AOT: is_resource() false after fclose; gettype resource (closed) (#30792)
--FILE--
<?php
$f = fopen('php://memory', 'r');
echo is_resource($f) ? '1' : '0', "\n";
fclose($f);
echo is_resource($f) ? '1' : '0', "\n";
echo gettype($f), "\n";
--EXPECT--
1
0
resource (closed)
