--TEST--
AOT: getimagesizefromstring(null) — TypeError on 8.4 forward profile (#20353, re-#19100, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
getimagesizefromstring(null);
--EXPECT--
--EXPECT_EXIT--
255
