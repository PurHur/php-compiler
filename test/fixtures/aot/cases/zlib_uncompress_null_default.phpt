--TEST--
AOT: gzuncompress(null) TypeError on default profile (#19004, ext/zlib/zlib.c)
--FILE--
<?php
gzuncompress(null);
--EXPECT--
--EXPECT_EXIT--
255
