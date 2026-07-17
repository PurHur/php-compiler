--TEST--
AOT: join(null) TypeError on 8.4 forward profile (#19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$sep = null;
join($sep, ['a']);
--EXPECT--
--EXPECT_EXIT--
255
