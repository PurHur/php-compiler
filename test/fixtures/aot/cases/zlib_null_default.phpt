--TEST--
AOT: gzcompress(null) coerces to empty payload on default profile (#19023, ext/zlib/zlib.c)
--FILE--
<?php
echo strlen(gzcompress(null)), "\n";
--EXPECT--
8
