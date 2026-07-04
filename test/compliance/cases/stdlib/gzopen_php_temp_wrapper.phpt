--TEST--
stdlib gzopen() php://temp read wrapper (ext/zlib/zlib.c, #9407)
--FILE--
<?php
declare(strict_types=1);
var_export(gzopen('php://temp', 'rb') !== false);
echo "\n";
$fp = gzopen('php://temp', 'rb');
var_export(gzread($fp, 10));
echo "\n";
var_export(gzeof($fp) === 1);
echo "\n";
gzclose($fp);
var_export(@gzopen('php://memory', 'wb') === false);
echo "\n";
?>
--EXPECT--
true
''
true
true
