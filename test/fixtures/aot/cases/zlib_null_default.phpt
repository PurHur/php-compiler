--TEST--
AOT: gzcompress(null) TypeError on default profile (#19004, ext/zlib/zlib.c)
--FILE--
<?php
gzcompress(null);
--EXPECT--
--EXPECT_EXIT--
255
