--TEST--
Language: by-ref parameter &$this is compile-time fatal (#32179, Zend/zend_compile.c)
--FILE--
<?php
function foo(&$this) {}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as parameter in %s on line %d
