--TEST--
Language: closure use($this) is compile-time fatal (#32152, Zend/zend_compile.c)
--FILE--
<?php
$f = function () use ($this) { return 1; };
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as lexical variable in %s on line %d
