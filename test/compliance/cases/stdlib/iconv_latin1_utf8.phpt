--TEST--
iconv() ISO-8859-1 to UTF-8 without host ext/iconv (#6251)
--FILE--
<?php
$bytes = "\xE9";
echo iconv('ISO-8859-1', 'UTF-8', $bytes), "\n";
echo mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1'), "\n";
echo extension_loaded('iconv') ? "iconv-loaded\n" : "iconv-missing\n";
?>
--EXPECT--
é
é
iconv-loaded
