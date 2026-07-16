--TEST--
AOT: htmlspecialchars null — TypeError on 8.4 forward profile (#19296)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
htmlspecialchars(null);
--EXPECT--
--EXPECT_EXIT--
255
