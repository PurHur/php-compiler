--TEST--
AOT: isset($_SERVER['PATH_INFO']) when absent must not warn (MiniWebApp, issue #539)
--ENV--
REQUEST_METHOD=GET
--FILE--
<?php
echo isset($_SERVER['PATH_INFO']) ? 'yes' : 'no';
--EXPECT--
no
--EXPECT_EXIT--
0
