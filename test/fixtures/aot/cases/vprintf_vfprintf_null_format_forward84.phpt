--TEST--
AOT: vfprintf()/fprintf() null format soft-null on 8.4 (#21514)
--ENV--
PHP_COMPILER_PROFILE=8.4
REQUEST_METHOD=
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$fmt = null;
$fp = fopen('php://memory', 'w+');
vfprintf($fp, $fmt, []);
fprintf($fp, $fmt);
fclose($fp);
echo "OK\n";
?>
--EXPECT--
OK
