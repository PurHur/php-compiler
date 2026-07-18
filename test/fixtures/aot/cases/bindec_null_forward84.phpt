--TEST--
AOT: bindec/hexdec/octdec null — TypeError on 8.4 forward profile (#20658)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
bindec(null);
--EXPECT--
--EXPECT_EXIT--
255
