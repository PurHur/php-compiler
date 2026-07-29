--TEST--
AOT: sprintf() trailing incomplete % ArgumentCountError (#24661, formatted_print.c)
--FILE--
<?php
sprintf('%');
--EXPECT--
--EXPECT_EXIT--
255
