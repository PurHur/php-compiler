--TEST--
stdlib parse_str() one-arg populates script scope (PHP 8+, #12533)
--FILE--
<?php
parse_str('route=home&page=3');
echo $GLOBALS['route'], "\n";
echo $GLOBALS['page'], "\n";
--EXPECT--
home
3
