--TEST--
Web: isset($_SERVER['PATH_INFO']) when absent must not warn (MiniWebApp, issue #539)
--FILE--
<?php
echo isset($_SERVER['PATH_INFO']) ? 'yes' : 'no';
--EXPECT--
no
