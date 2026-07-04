--TEST--
zlib gzeof() fresh read handle returns bool false (ext/zlib/zlib.c, #16175)
--FILE--
<?php
declare(strict_types=1);
$fp = gzopen('php://temp', 'rb');
var_export(gzeof($fp));
echo "\n";
gzread($fp, 10);
var_export(gzeof($fp));
echo "\n";
gzclose($fp);
?>
--EXPECT--
false
true
