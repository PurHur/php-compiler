--TEST--
Language: unset($GLOBALS) is compile-time fatal (#32229, Zend/zend_compile.c)
--FILE--
<?php
unset($GLOBALS);
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  $GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax in %s on line %d
