--TEST--
Language: arrow fn($this) is compile-time fatal (#32179, Zend/zend_compile.c)
--FILE--
<?php
$f = fn($this) => 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as parameter in %s on line %d
