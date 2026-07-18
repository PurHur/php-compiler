--TEST--
AOT: printf(null) TypeError on 8.4 forward profile (#20197, formatted_print.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
printf(null);
--EXPECT--
--EXPECT_EXIT--
255
