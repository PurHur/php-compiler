--TEST--
stdlib Dom\HTML_NO_DEFAULT_NS — withheld on PHP 8.2 reference profile (#26008)
--FILE--
<?php
echo defined('Dom\\HTML_NO_DEFAULT_NS') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
