--TEST--
AOT: gzinflate(null) TypeError on default profile (#19004, ext/zlib/zlib.c)
--FILE--
<?php
gzinflate(null);
--EXPECT--
--EXPECT_EXIT--
255
