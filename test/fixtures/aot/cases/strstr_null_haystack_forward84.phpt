--TEST--
AOT: strstr/strpos null haystack — TypeError on 8.4 forward profile (#19242)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strstr(null, 'x');
--EXPECT--
--EXPECT_EXIT--
255
