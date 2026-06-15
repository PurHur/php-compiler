--TEST--
iconv() JIT — ISO-8859-1 to UTF-8 without host ext/iconv (#6009, #6251)
--FILE--
<?php
$bytes = "\xE9";
$from = 'ISO-8859-1';
$to = 'UTF-8';
echo iconv($from, $to, $bytes), "\n";
echo extension_loaded('iconv') ? "iconv-loaded\n" : "iconv-missing\n";
?>
--EXPECT--
é
iconv-loaded
