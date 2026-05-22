--TEST--
define() with literal name and value (issue #204)
--FILE--
<?php
define('Y', 1);
echo Y;
--EXPECT--
1
