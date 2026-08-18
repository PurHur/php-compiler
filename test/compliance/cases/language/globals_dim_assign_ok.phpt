--TEST--
Language: $GLOBALS[$name] = $value remains legal (#32229, Zend/zend_compile.c)
--FILE--
<?php
$GLOBALS['x'] = 1;
echo $GLOBALS['x'];
echo "\n";
--EXPECT--
1
