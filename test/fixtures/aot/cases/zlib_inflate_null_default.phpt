--TEST--
AOT: gzinflate(null) warns and returns false on default profile (#19023, ext/zlib/zlib.c)
--FILE--
<?php
var_export(@gzinflate(null));
echo "\n";
--EXPECT--
false
