--TEST--
zlib gzcompress/gzuncompress/gzinflate(null) — coerce to empty string on default profile (#19023, ext/zlib/zlib.c)
--FILE--
<?php
$c = gzcompress(null);
echo strlen($c), "\n";
echo var_export(@gzuncompress(null), true), "\n";
echo var_export(@gzinflate(null), true), "\n";
?>
--EXPECT--
8
false
false
