--TEST--
AOT: sprintf('%', 1) trailing % ValueError (#24661, formatted_print.c)
--FILE--
<?php
sprintf('%', 1);
--EXPECT--
--EXPECT_EXIT--
255
