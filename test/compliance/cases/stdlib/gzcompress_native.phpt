--TEST--
stdlib gzcompress/gzuncompress via VmZlibCore pure PHP without host ext-zlib (#6356, #8837)
--FILE--
<?php
$plain = 'hello bootstrap';
$z = gzcompress($plain);
echo is_string($z) ? '1' : '0';
echo gzuncompress($z) === $plain ? '1' : '0';
echo "\n";
--EXPECT--
11
