--TEST--
AOT: interface_exists(null) TypeError on 8.4 forward profile (#19223)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
interface_exists(null);
--EXPECT--
--EXPECT_EXIT--
255
