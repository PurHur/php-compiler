--TEST--
AOT: getimagesizefromstring(null) TypeError on default profile (#19003, ext/standard/image.c)
--FILE--
<?php
getimagesizefromstring(null);
--EXPECT--
--EXPECT_EXIT--
255
